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

class AddRedisNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public RedisCluster $cluster,
        public RedisNode $node,
    ) {}

    public static function progressKey(int $nodeId): string
    {
        return "add_redis_node_progress_{$nodeId}";
    }

    protected function getCacheKey(): string
    {
        return self::progressKey($this->node->id);
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
            if ($os) {
                $this->addStep("OS: {$os}", 'success');
            } else {
                throw new \RuntimeException('Unable to detect OS.');
            }

            // Step 3: Detect system hardware
            $this->addStep('Detecting system hardware...');
            $provisionService->detectSystemInfo($node);
            $this->addStep('System info detected.', 'success');

            // Step 4: Install Redis
            $this->addStep('Installing Redis Server and Sentinel...');
            $installResult = $provisionService->installRedis($node);
            if (! $installResult['redis_installed'] || ! $installResult['sentinel_installed']) {
                throw new \RuntimeException('Redis/Sentinel installation failed.');
            }
            $this->addStep("Redis installed: {$installResult['output']}", 'success');

            // Step 5: Write Redis config (as replica)
            $this->addStep('Writing Redis configuration (replica mode)...');
            $node->update(['role' => 'replica']);
            $node->refresh();
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

            // Step 7: Verify replication
            $this->addStep('Verifying replication...');
            $pingResult = $redisCli->ping($node, $cluster->auth_password_encrypted);
            if (! $pingResult['success'] || ! str_contains($pingResult['output'], 'PONG')) {
                throw new \RuntimeException('Redis replica is not responding.');
            }
            $this->addStep('Redis replica responding.', 'success');

            // Step 8: Write Sentinel config
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

            // Step 11: Update firewall on existing nodes to allow new node
            $this->addStep('Updating firewall on existing nodes...');
            $firewallService->allowNewRedisNodeOnCluster($cluster, $node);
            $this->addStep('Existing node firewalls updated.', 'success');

            // Step 12: Update records
            $node->update(['status' => 'online']);

            $this->addStep('Redis replica node added successfully!', 'success');
            $this->setStatus('complete');

        } catch (\Throwable $e) {
            Log::error("Add Redis node failed: {$e->getMessage()}", [
                'cluster_id' => $cluster->id,
                'node_id' => $node->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addStep("Error: {$e->getMessage()}", 'error');
            $this->setStatus('failed');

            $node->update(['status' => 'error']);
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
