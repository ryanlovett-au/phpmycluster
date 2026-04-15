<?php

namespace App\Jobs;

use App\Jobs\Concerns\ProvisionesNode;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\FirewallService;
use App\Services\MysqlProvisionService;
use App\Services\MysqlShellService;
use App\Services\SshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AddNodeJob implements ShouldQueue
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
        public MysqlCluster $cluster,
        public MysqlNode $node,
    ) {}

    /**
     * Get the cache key for storing progress.
     */
    public static function progressKey(int $nodeId): string
    {
        return "add_node_progress_{$nodeId}";
    }

    /**
     * Get the cache key for this job instance.
     */
    protected function getCacheKey(): string
    {
        return self::progressKey($this->node->id);
    }

    /**
     * Get the root password for configureInstance.
     * For secondary nodes, root may use auth_socket so we try the cluster admin password.
     */
    protected function getRootPassword(MysqlCluster $cluster, MysqlNode $node): string
    {
        return $cluster->cluster_admin_password_encrypted;
    }

    /**
     * Execute the job.
     */
    public function handle(
        MysqlProvisionService $provisionService,
        FirewallService $firewallService,
        MysqlShellService $mysqlShell,
        SshService $sshService,
    ): void {
        $cluster = $this->cluster->fresh();
        $node = $this->node->fresh();

        try {
            // Shared provisioning: detect state, install MySQL, configure instance
            $state = $this->provisionNode($cluster, $node, $provisionService, $mysqlShell, $sshService);

            // Step 7: Configure firewall on new node
            $this->addStep('Configuring firewall on new node...');
            $firewallService->configureDbNode($node, $cluster);
            $this->addStep('Firewall configured on new node.', 'success');

            // Step 8: Update firewall on existing cluster nodes to allow new node
            $this->addStep('Updating firewall on existing nodes...');
            $firewallService->allowNewNodeOnCluster($cluster, $node);
            $cluster->update(['ip_allowlist' => $cluster->buildIpAllowlist()]);
            $this->addStep('Existing nodes updated.', 'success');

            // Step 9: Add instance to cluster via primary
            $primary = $cluster->primaryNode();
            if (! $primary) {
                throw new \RuntimeException('No primary node found in cluster.');
            }

            $this->addStep("Adding node to cluster via {$primary->name}...");
            $result = $mysqlShell->addInstance($primary, $node, $cluster, $cluster->cluster_admin_password_encrypted);

            if ($result['success'] && ! isset($result['data']['error'])) {
                $node->update(['role' => 'secondary', 'status' => 'online']);
                $this->addStep('Node added to cluster!', 'success');
            } else {
                throw new \RuntimeException('Failed to add instance: '.($result['data']['error'] ?? $result['raw_output']));
            }

            // Step 10: Update cluster status
            $this->addStep('Refreshing cluster status...');
            $statusResult = $mysqlShell->getClusterStatus($primary, $cluster->cluster_admin_password_encrypted);
            if ($statusResult['success'] && $statusResult['data'] && ! isset($statusResult['data']['error'])) {
                $cluster->update([
                    'last_status_json' => $statusResult['data'],
                    'last_checked_at' => now(),
                ]);
            }
            $this->addStep('Cluster status refreshed.', 'success');

            $this->addStep('Node setup complete!', 'success');
            $this->setStatus('complete');

        } catch (\Throwable $e) {
            Log::error("Add node failed: {$e->getMessage()}", [
                'cluster_id' => $cluster->id,
                'node_id' => $node->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addStep("Error: {$e->getMessage()}", 'error');
            $this->setStatus('failed');

            $node->update(['status' => 'error']);
        }
    }
}
