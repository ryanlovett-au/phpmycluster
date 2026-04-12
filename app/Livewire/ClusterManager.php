<?php

namespace App\Livewire;

use App\Models\Cluster;
use App\Models\Node;
use App\Services\FirewallService;
use App\Services\MysqlShellService;
use App\Services\NodeProvisionService;
use App\Services\SshService;
use Livewire\Component;

class ClusterManager extends Component
{
    public Cluster $cluster;

    // Add node form
    public bool $showAddNode = false;

    public string $newNodeHost = '';

    public string $newNodeName = '';

    public int $newNodeSshPort = 22;

    public string $newNodeSshUser = 'root';

    public string $newNodeSshKeyMode = 'generate';

    public string $newNodePrivateKey = '';

    public ?array $newNodeKeyPair = null;

    // Status
    public ?array $clusterStatus = null;

    public bool $refreshing = false;

    // Action feedback
    public string $actionMessage = '';

    public string $actionStatus = ''; // success, error, info

    public function mount(Cluster $cluster)
    {
        $this->cluster = $cluster->load('nodes');
    }

    /**
     * Refresh cluster status from the primary node.
     */
    public function refreshStatus()
    {
        $this->refreshing = true;
        $primary = $this->cluster->primaryNode();

        if (! $primary) {
            $this->setMessage('No primary node found.', 'error');
            $this->refreshing = false;

            return;
        }

        $mysqlShell = app(MysqlShellService::class);
        $result = $mysqlShell->getClusterStatus($primary, $this->cluster->cluster_admin_password_encrypted);

        if ($result['success'] && $result['data'] && ! isset($result['data']['error'])) {
            $this->clusterStatus = $result['data'];
            $this->cluster->update([
                'last_status_json' => $result['data'],
                'last_checked_at' => now(),
            ]);
            $this->updateNodeStatuses($result['data']);
            $this->setMessage('Cluster status refreshed.', 'success');
        } else {
            $this->setMessage('Failed to get cluster status: '.($result['data']['error'] ?? $result['raw_output']), 'error');
        }

        $this->refreshing = false;
    }

    /**
     * Generate SSH key for new node.
     */
    public function generateNewNodeKey()
    {
        $this->newNodeKeyPair = app(SshService::class)->generateKeyPair();
    }

    /**
     * Add a new node to the cluster.
     */
    public function addNode()
    {
        $this->validate([
            'newNodeHost' => 'required|string',
        ]);

        $privateKey = $this->newNodeSshKeyMode === 'generate'
            ? $this->newNodeKeyPair['private']
            : $this->newNodePrivateKey;

        $publicKey = $this->newNodeSshKeyMode === 'generate'
            ? $this->newNodeKeyPair['public']
            : '';

        try {
            // Determine server_id
            $maxServerId = $this->cluster->nodes()->max('server_id') ?? 0;

            $node = Node::create([
                'cluster_id' => $this->cluster->id,
                'name' => $this->newNodeName ?: 'node-'.($maxServerId + 1)."-{$this->newNodeHost}",
                'host' => $this->newNodeHost,
                'ssh_port' => $this->newNodeSshPort,
                'ssh_user' => $this->newNodeSshUser,
                'ssh_private_key_encrypted' => $privateKey,
                'ssh_public_key' => $publicKey,
                'role' => 'pending',
                'server_id' => $maxServerId + 1,
            ]);

            $provisionService = app(NodeProvisionService::class);
            $firewallService = app(FirewallService::class);
            $mysqlShell = app(MysqlShellService::class);

            // Install MySQL on the new node
            $this->setMessage('Installing MySQL on new node...', 'info');
            $installResult = $provisionService->installMysql($node);
            if (! $installResult['mysql_installed']) {
                throw new \RuntimeException('Failed to install MySQL on new node.');
            }

            // Write config
            $provisionService->writeMysqlConfig($node);
            $provisionService->restartMysql($node);

            // Configure instance
            // Note: for adding nodes, we use the cluster admin password since root may differ
            $mysqlShell->configureInstance(
                $node,
                $this->cluster->cluster_admin_password_encrypted, // try cluster admin as root
                $this->cluster->cluster_admin_user,
                $this->cluster->cluster_admin_password_encrypted,
            );
            $provisionService->restartMysql($node);
            sleep(3);

            // Update firewall on all existing nodes to allow the new node
            $firewallService->allowNewNodeOnCluster($this->cluster, $node);

            // Configure firewall on the new node
            $this->cluster->refresh();
            $firewallService->configureDbNode($node, $this->cluster);

            // Update the cluster IP allowlist
            $this->cluster->update(['ip_allowlist' => $this->cluster->buildIpAllowlist()]);

            // Add to cluster via primary
            $primary = $this->cluster->primaryNode();
            $result = $mysqlShell->addInstance($primary, $node, $this->cluster, $this->cluster->cluster_admin_password_encrypted);

            if ($result['success'] && ! isset($result['data']['error'])) {
                $node->update(['role' => 'secondary', 'status' => 'online']);
                $this->setMessage("Node {$node->name} added to cluster successfully.", 'success');
            } else {
                throw new \RuntimeException('Failed to add instance: '.($result['data']['error'] ?? $result['raw_output']));
            }

            $this->resetAddNodeForm();
            $this->cluster->refresh();
            $this->refreshStatus();

        } catch (\Throwable $e) {
            $this->setMessage("Error adding node: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Remove a node from the cluster.
     */
    public function removeNode(int $nodeId, bool $force = false)
    {
        $node = Node::findOrFail($nodeId);
        $primary = $this->cluster->primaryNode();

        if (! $primary || $primary->id === $nodeId) {
            $this->setMessage('Cannot remove the primary node. Switch primary first.', 'error');

            return;
        }

        $mysqlShell = app(MysqlShellService::class);
        $result = $mysqlShell->removeInstance($primary, $node, $this->cluster->cluster_admin_password_encrypted, $force);

        if ($result['success'] && ! isset($result['data']['error'])) {
            $node->update(['role' => 'pending', 'status' => 'offline', 'cluster_id' => null]);
            $this->cluster->update(['ip_allowlist' => $this->cluster->buildIpAllowlist()]);
            $this->setMessage("Node {$node->name} removed from cluster.", 'success');
            $this->cluster->refresh();
        } else {
            $this->setMessage('Failed to remove node: '.($result['data']['error'] ?? $result['raw_output']), 'error');
        }
    }

    /**
     * Rejoin a node that has gone offline.
     */
    public function rejoinNode(int $nodeId)
    {
        $node = Node::findOrFail($nodeId);
        $primary = $this->cluster->primaryNode();

        if (! $primary) {
            $this->setMessage('No primary node available.', 'error');

            return;
        }

        $mysqlShell = app(MysqlShellService::class);
        $result = $mysqlShell->rejoinInstance($primary, $node, $this->cluster->cluster_admin_password_encrypted);

        if ($result['success'] && ! isset($result['data']['error'])) {
            $this->setMessage("Node {$node->name} rejoining cluster.", 'success');
            $this->refreshStatus();
        } else {
            $this->setMessage('Failed to rejoin: '.($result['data']['error'] ?? $result['raw_output']), 'error');
        }
    }

    /**
     * Force quorum when majority is lost.
     */
    public function forceQuorum(int $nodeId)
    {
        $node = Node::findOrFail($nodeId);
        $mysqlShell = app(MysqlShellService::class);

        $result = $mysqlShell->forceQuorum($node, $this->cluster->cluster_admin_password_encrypted);

        if ($result['success'] && ! isset($result['data']['error'])) {
            $this->setMessage('Quorum restored.', 'success');
            $this->refreshStatus();
        } else {
            $this->setMessage('Force quorum failed: '.($result['data']['error'] ?? $result['raw_output']), 'error');
        }
    }

    /**
     * Reboot cluster from complete outage.
     */
    public function rebootCluster(int $nodeId)
    {
        $node = Node::findOrFail($nodeId);
        $mysqlShell = app(MysqlShellService::class);

        $result = $mysqlShell->rebootCluster($node, $this->cluster->cluster_admin_password_encrypted);

        if ($result['success'] && ! isset($result['data']['error'])) {
            $this->setMessage('Cluster rebooted from outage.', 'success');
            $this->refreshStatus();
        } else {
            $this->setMessage('Reboot failed: '.($result['data']['error'] ?? $result['raw_output']), 'error');
        }
    }

    /**
     * Rescan cluster topology.
     */
    public function rescan()
    {
        $primary = $this->cluster->primaryNode();

        if (! $primary) {
            $this->setMessage('No primary node available.', 'error');

            return;
        }

        $mysqlShell = app(MysqlShellService::class);
        $result = $mysqlShell->rescanCluster($primary, $this->cluster->cluster_admin_password_encrypted);

        if ($result['success']) {
            $this->setMessage('Cluster rescanned.', 'success');
            $this->refreshStatus();
        } else {
            $this->setMessage('Rescan failed: '.($result['data']['error'] ?? $result['raw_output']), 'error');
        }
    }

    /**
     * Update local node status records from cluster status JSON.
     */
    protected function updateNodeStatuses(array $statusData): void
    {
        $topology = $statusData['defaultReplicaSet']['topology'] ?? [];

        foreach ($topology as $address => $memberData) {
            $host = explode(':', $address)[0];

            $node = $this->cluster->nodes()->where('host', $host)->first();
            if ($node) {
                $memberStatus = strtolower($memberData['status'] ?? 'unknown');
                $role = strtolower($memberData['memberRole'] ?? 'secondary');

                $node->update([
                    'status' => $memberStatus === 'online' ? 'online' : ($memberStatus === 'recovering' ? 'recovering' : 'error'),
                    'role' => $role === 'primary' ? 'primary' : 'secondary',
                    'last_health_json' => $memberData,
                    'last_checked_at' => now(),
                ]);
            }
        }
    }

    protected function setMessage(string $message, string $status): void
    {
        $this->actionMessage = $message;
        $this->actionStatus = $status;
    }

    protected function resetAddNodeForm(): void
    {
        $this->showAddNode = false;
        $this->newNodeHost = '';
        $this->newNodeName = '';
        $this->newNodeSshPort = 22;
        $this->newNodeSshUser = 'root';
        $this->newNodeKeyPair = null;
        $this->newNodePrivateKey = '';
    }

    public function render()
    {
        return view('livewire.cluster-manager', [
            'nodes' => $this->cluster->nodes()->get(),
        ]);
    }
}
