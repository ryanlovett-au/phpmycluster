<?php

namespace App\Jobs;

use App\Models\Cluster;
use App\Models\Node;
use App\Services\FirewallService;
use App\Services\NodeProvisionService;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SetupRouterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Router setup is quicker than DB node provisioning, but allow plenty of time.
     */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public Cluster $cluster,
        public Node $node,
        public string $allowFrom = 'any',
    ) {}

    /**
     * Get the cache key for storing progress.
     */
    public static function progressKey(int $nodeId): string
    {
        return "setup_router_progress_{$nodeId}";
    }

    /**
     * Execute the job.
     */
    public function handle(
        NodeProvisionService $provisionService,
        FirewallService $firewallService,
        SshService $sshService,
    ): void {
        $cluster = $this->cluster->fresh();
        $node = $this->node->fresh();

        try {
            // Step 1: Test SSH connection
            $this->addStep('Testing SSH connection...');
            $testResult = $sshService->testConnection($node);
            if (! $testResult['success']) {
                throw new \RuntimeException('SSH connection failed: '.($testResult['error'] ?? 'Unknown error'));
            }
            $this->addStep("Connected to {$testResult['hostname']}.", 'success');

            // Step 2: Detect OS
            $os = $testResult['os'] ?? null;
            if ($os) {
                $this->addStep("OS: {$os}", 'success');
            } else {
                $this->addStep('OS: could not be detected.', 'error');
                throw new \RuntimeException('Unable to detect OS.');
            }

            // Step 3: Check if MySQL Router is already installed and running
            $this->addStep('Checking for existing MySQL Router installation...');
            $existingStatus = $provisionService->getRouterStatus($node);
            if ($existingStatus['running']) {
                $this->addStep('MySQL Router already installed and running.', 'success');
                $node->update(['status' => 'online', 'mysql_router_installed' => true]);
            } else {
                // Step 4: Install MySQL Router (sets up APT repo if needed)
                $this->addStep('Installing MySQL Router from official MySQL repo (this may take a few minutes)...');
                $installResult = $provisionService->installMysqlRouter($node, $cluster->mysql_apt_config_version);
                if (! $installResult['installed']) {
                    throw new \RuntimeException('Failed to install MySQL Router. Check that the node has internet access.');
                }
                $this->addStep("MySQL Router installed: {$installResult['version']}", 'success');
            }

            // Step 5: Configure firewall on router node
            $this->addStep('Configuring firewall on router node...');
            $firewallService->configureAccessNode($node, $cluster, $this->allowFrom);
            $this->addStep('Firewall configured on router.', 'success');

            // Step 6: Update DB node firewalls to allow router
            $this->addStep('Updating firewall on DB nodes...');
            foreach ($cluster->dbNodes as $dbNode) {
                $sshService->exec(
                    $dbNode,
                    "ufw allow from {$node->host} to any port {$dbNode->mysql_port} proto tcp comment 'MySQL from router {$node->name}'",
                    'firewall.rule',
                    sudo: true
                );
            }
            $this->addStep('DB node firewalls updated.', 'success');

            // Step 7: Bootstrap router against primary
            $primary = $cluster->primaryNode();
            if (! $primary) {
                throw new \RuntimeException('No primary node found to bootstrap router against.');
            }

            $this->addStep("Bootstrapping router against {$primary->name}...");
            $bootstrapResult = $provisionService->bootstrapRouter(
                $node,
                $primary,
                $cluster->cluster_admin_password_encrypted
            );

            if ($bootstrapResult['success']) {
                $node->update(['status' => 'online', 'mysql_router_installed' => true]);
                $this->addStep('Router bootstrapped and running!', 'success');
            } else {
                throw new \RuntimeException('Router bootstrap failed: '.$bootstrapResult['output']);
            }

            $this->addStep('Router setup complete!', 'success');
            $this->setStatus('complete');

        } catch (\Throwable $e) {
            Log::error("Router setup failed: {$e->getMessage()}", [
                'cluster_id' => $cluster->id,
                'node_id' => $node->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addStep("Error: {$e->getMessage()}", 'error');
            $this->setStatus('failed');

            $node->update(['status' => 'error']);
        }
    }

    /**
     * Add a progress step to the cache.
     */
    protected function addStep(string $message, string $status = 'running'): void
    {
        $key = self::progressKey($this->node->id);
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

    /**
     * Set the overall provision status.
     */
    protected function setStatus(string $status): void
    {
        $key = self::progressKey($this->node->id);
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
