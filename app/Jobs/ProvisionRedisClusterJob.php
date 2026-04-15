<?php

namespace App\Jobs;

use App\Models\RedisCluster;
use App\Models\RedisNode;
use App\Services\FirewallService;
use App\Services\RedisCliService;
use App\Services\RedisProvisionService;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProvisionRedisClusterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public RedisCluster $cluster,
        public RedisNode $node,
    ) {}

    public static function progressKey(int $clusterId): string
    {
        return "provision_redis_progress_{$clusterId}";
    }

    protected function getCacheKey(): string
    {
        return self::progressKey($this->cluster->id);
    }

    public function handle(
        RedisProvisionService $provisionService,
        RedisCliService $redisCli,
        FirewallService $firewallService,
        SshService $sshService,
    ): void {
        $cluster = $this->cluster->fresh();
        $node = $this->node->fresh();

        try {
            // Step 1: Test SSH connection
            $this->addStep('Testing SSH connection...');
            $testResult = $sshService->testConnection($node->server);
            if (! $testResult['success']) {
                throw new \RuntimeException('SSH connection failed: '.($testResult['error'] ?? 'Unknown error'));
            }
            $this->addStep("Connected to {$testResult['hostname']}.", 'success');

            // Step 2: Detect OS
            $os = $testResult['os'] ?? null;
            if (! $os) {
                $this->addStep('Detecting operating system...');
                $osInfo = $provisionService->detectOs($node);
                $os = $osInfo['pretty_name'] ?? null;
            }
            if ($os) {
                $this->addStep("OS: {$os}", 'success');
            } else {
                throw new \RuntimeException('Unable to detect OS. Provisioning requires Debian or Ubuntu.');
            }

            // Step 3: Detect system hardware
            $this->addStep('Detecting system hardware...');
            $sysInfo = $provisionService->detectSystemInfo($node);
            if ($sysInfo['ram_mb'] > 0) {
                $ramGb = round($sysInfo['ram_mb'] / 1024, 1);
                $this->addStep("{$ramGb}GB RAM, {$sysInfo['cpu_cores']} CPU cores detected.", 'success');
            } else {
                $this->addStep('Could not detect RAM — using default settings.', 'success');
            }

            // Step 4: Install Redis and Sentinel
            $this->addStep('Installing Redis Server and Sentinel from official repo...');
            $installResult = $provisionService->installRedis($node);
            if (! $installResult['redis_installed']) {
                throw new \RuntimeException('Redis installation failed. Check audit logs for details.');
            }
            $this->addStep("Redis installed: {$installResult['output']}", 'success');

            if (! $installResult['sentinel_installed']) {
                throw new \RuntimeException('Sentinel installation failed. Check audit logs for details.');
            }

            // Update cluster version if not set
            if (! $cluster->redis_version && $installResult['redis_version']) {
                $cluster->update(['redis_version' => $installResult['redis_version']]);
            }

            // Step 5: Write Redis configuration
            $this->addStep('Writing Redis configuration...');
            $configResult = $provisionService->writeRedisConfig($node, $cluster);
            if (! $configResult['success']) {
                throw new \RuntimeException('Failed to write Redis configuration.');
            }
            $this->addStep('Redis configuration written.', 'success');

            // Step 6: Start Redis
            $this->addStep('Starting Redis...');
            $restartResult = $provisionService->restartRedis($node);
            if (! $restartResult['success']) {
                throw new \RuntimeException('Failed to start Redis.');
            }
            sleep(2);
            $this->addStep('Redis started.', 'success');

            // Step 7: Verify Redis is responding
            $this->addStep('Verifying Redis connectivity...');
            $pingResult = $redisCli->ping($node, $cluster->auth_password_encrypted);
            if (! $pingResult['success'] || ! str_contains($pingResult['output'], 'PONG')) {
                throw new \RuntimeException('Redis is not responding to PING.');
            }
            $this->addStep('Redis responding to PING.', 'success');

            // Mark this node as master so Sentinel config can reference it
            $node->update(['role' => 'master']);

            // Step 8: Write Sentinel configuration
            $this->addStep('Writing Sentinel configuration...');
            $sentinelResult = $provisionService->writeSentinelConfig($node, $cluster);
            if (! $sentinelResult['success']) {
                throw new \RuntimeException('Failed to write Sentinel configuration.');
            }
            $this->addStep('Sentinel configuration written.', 'success');

            // Step 9: Start Sentinel
            $this->addStep('Starting Sentinel...');
            $sentinelRestart = $provisionService->restartSentinel($node);
            if (! $sentinelRestart['success']) {
                throw new \RuntimeException('Failed to start Sentinel.');
            }
            sleep(2);
            $this->addStep('Sentinel started.', 'success');

            // Step 10: Configure firewall
            $this->addStep('Configuring firewall...');
            $firewallService->configureRedisNode($node, $cluster);
            $this->addStep('Firewall configured.', 'success');

            // Step 11: Update records
            $node->update(['role' => 'master', 'status' => 'online']);
            $cluster->update([
                'status' => 'online',
                'last_checked_at' => now(),
            ]);

            $this->addStep('Redis Sentinel cluster setup complete!', 'success');
            $this->setStatus('complete');

        } catch (\Throwable $e) {
            Log::error("Redis cluster provisioning failed: {$e->getMessage()}", [
                'cluster_id' => $cluster->id,
                'node_id' => $node->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addStep("Error: {$e->getMessage()}", 'error');
            $this->setStatus('failed');

            $cluster->update(['status' => 'error']);
        }
    }

    protected function addStep(string $message, string $status = 'running'): void
    {
        $key = $this->getCacheKey();
        $progress = Cache::get($key, ['steps' => [], 'status' => 'running']);

        foreach ($progress['steps'] as &$step) {
            if ($step['status'] === 'running') {
                $step['status'] = 'success';
            }
        }
        unset($step);

        $progress['steps'][] = [
            'message' => $message,
            'status' => $status,
            'time' => now()->format('H:i:s'),
        ];

        Cache::put($key, $progress, now()->addHours(2));
    }

    protected function setStatus(string $status): void
    {
        $key = $this->getCacheKey();
        $progress = Cache::get($key, ['steps' => [], 'status' => 'running']);
        $progress['status'] = $status;

        $resolvedStatus = $status === 'complete' ? 'success' : 'error';
        foreach ($progress['steps'] as &$step) {
            if ($step['status'] === 'running') {
                $step['status'] = $resolvedStatus;
            }
        }

        Cache::put($key, $progress, now()->addHours(2));
    }
}
