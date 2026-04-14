<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProvisionesNode;
use App\Models\Cluster;
use App\Models\Node;
use App\Services\FirewallService;
use App\Services\MysqlShellService;
use App\Services\NodeProvisionService;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionClusterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ProvisionesNode, Queueable, SerializesModels;

    /**
     * The job may take up to 30 minutes for a full provision.
     */
    public int $timeout = 1800;

    /**
     * Only attempt once — provisioning is not idempotent.
     */
    public int $tries = 1;

    public function __construct(
        public Cluster $cluster,
        public Node $node,
        public string $mysqlRootPassword,
        public string $clusterAdminPassword,
    ) {}

    /**
     * Get the cache key for storing progress.
     */
    public static function progressKey(int $clusterId): string
    {
        return "provision_progress_{$clusterId}";
    }

    /**
     * Get the cache key for this job instance.
     */
    protected function getCacheKey(): string
    {
        return self::progressKey($this->cluster->id);
    }

    /**
     * Get the root password for configureInstance.
     * For the primary node, we use the actual MySQL root password.
     */
    protected function getRootPassword(Cluster $cluster, Node $node): string
    {
        return $this->mysqlRootPassword;
    }

    /**
     * Execute the job.
     */
    public function handle(
        NodeProvisionService $provisionService,
        FirewallService $firewallService,
        MysqlShellService $mysqlShell,
        SshService $sshService,
    ): void {
        $cluster = $this->cluster->fresh();
        $node = $this->node->fresh();

        try {
            // Shared provisioning: detect state, install MySQL, configure instance
            $state = $this->provisionNode($cluster, $node, $provisionService, $mysqlShell, $sshService);

            // Step 7: Configure firewall
            $this->addStep('Configuring UFW firewall...');
            $firewallService->configureDbNode($node, $cluster);
            $this->addStep('Firewall configured.', 'success');

            // Step 8: Create the cluster (skip if already exists)
            if ($state['cluster_exists']) {
                $this->addStep('InnoDB Cluster already exists, fetching status...', 'success');
                $statusResult = $mysqlShell->getClusterStatus($node, $this->clusterAdminPassword);
                $createData = $statusResult['data'] ?? [];
            } else {
                $this->addStep('Creating InnoDB Cluster...');
                $createResult = $mysqlShell->createCluster($node, $cluster, $this->clusterAdminPassword);
                if (! $createResult['success'] || isset($createResult['data']['error'])) {
                    throw new \RuntimeException('Failed to create cluster: '.($createResult['data']['error'] ?? $createResult['raw_output']));
                }
                $this->addStep('InnoDB Cluster created!', 'success');
                $createData = $createResult['data'];
            }

            // Step 9: Update records
            $node->update(['role' => 'primary', 'status' => 'online']);
            $cluster->update([
                'status' => 'online',
                'last_status_json' => $createData,
                'ip_allowlist' => $cluster->buildIpAllowlist(),
                'last_checked_at' => now(),
            ]);

            $this->addStep('Cluster setup complete!', 'success');
            $this->setStatus('complete');

        } catch (\Throwable $e) {
            Log::error("Cluster provisioning failed: {$e->getMessage()}", [
                'cluster_id' => $cluster->id,
                'node_id' => $node->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addStep("Error: {$e->getMessage()}", 'error');
            $this->setStatus('failed');

            $cluster->update(['status' => 'error']);
        }
    }
}
