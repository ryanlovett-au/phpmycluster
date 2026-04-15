<?php

namespace App\Livewire;

use App\Jobs\AddRedisNodeJob;
use App\Jobs\RefreshRedisStatusJob;
use App\Models\RedisCluster;
use App\Models\RedisNode;
use App\Models\Server;
use App\Services\FirewallService;
use App\Services\RedisCliService;
use App\Services\SshService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class RedisClusterManager extends Component
{
    public RedisCluster $cluster;

    // Add node form
    public bool $showAddNode = false;

    public string $newNodeServerMode = 'new'; // new or existing

    public ?int $newNodeSelectedServerId = null;

    public string $newNodeHost = '';

    public string $newNodeName = '';

    public int $newNodeSshPort = 22;

    public string $newNodeSshUser = 'root';

    public int $newNodeRedisPort = 6379;

    public int $newNodeSentinelPort = 26379;

    public string $newNodeSshKeyMode = 'generate';

    public string $newNodePrivateKey = '';

    public ?array $newNodeKeyPair = null;

    // Add node provisioning progress
    public bool $addingNode = false;

    public bool $addNodeComplete = false;

    public ?int $addingNodeId = null;

    public array $addNodeSteps = [];

    // Firewall
    public bool $showFirewallModal = false;

    public ?int $firewallNodeId = null;

    public string $firewallOutput = '';

    public string $firewallNewIp = '';

    public array $firewallRules = [];

    // Rename node
    public ?int $renamingNodeId = null;

    public string $renameNodeValue = '';

    // Status
    public bool $refreshing = false;

    public ?string $refreshBatchId = null;

    // SSH test result
    public string $sshTestResult = '';

    // Action feedback
    public string $actionMessage = '';

    public string $actionStatus = ''; // success, error, info

    public function mount(RedisCluster $cluster)
    {
        $this->cluster = $cluster->load('nodes');
    }

    // ─── Cluster Status ─────────────────────────────────────────────────

    /**
     * Refresh cluster status by dispatching a background job via Bus::batch.
     */
    public function refreshStatus()
    {
        $this->refreshing = true;

        try {
            $batch = Bus::batch([
                new RefreshRedisStatusJob($this->cluster),
            ])
                ->name("Refresh {$this->cluster->name}")
                ->allowFailures()
                ->dispatch();

            $this->refreshBatchId = $batch->id;
        } catch (\Throwable $e) {
            $this->setMessage("Failed to dispatch refresh: {$e->getMessage()}", 'error');
            $this->refreshing = false;
        }
    }

    /**
     * Poll the refresh batch for completion.
     */
    public function pollRefresh(): void
    {
        if (! $this->refreshBatchId) {
            return;
        }

        $batch = Bus::findBatch($this->refreshBatchId);

        if (! $batch || $batch->finished()) {
            $this->cluster->refresh();

            $failedJobs = $batch?->failedJobs ?? 0;
            if ($failedJobs > 0) {
                $this->setMessage("Cluster refreshed with {$failedJobs} failed check(s).", 'error');
            } else {
                $this->setMessage('Cluster status refreshed.', 'success');
            }

            $this->refreshing = false;
            $this->refreshBatchId = null;
        }
    }

    // ─── Add Node ───────────────────────────────────────────────────────

    /**
     * Generate SSH key for new node.
     */
    public function generateNewNodeKey()
    {
        $this->newNodeKeyPair = app(SshService::class)->generateKeyPair();
    }

    /**
     * Add a new replica node to the cluster — dispatches a background job.
     */
    public function addNode()
    {
        if ($this->newNodeServerMode === 'existing') {
            $this->validate([
                'newNodeSelectedServerId' => 'required|exists:servers,id',
            ], [
                'newNodeSelectedServerId.required' => 'Please select a server.',
            ]);
        } else {
            $this->validate([
                'newNodeHost' => 'required|string',
            ]);
        }

        try {
            if ($this->newNodeServerMode === 'existing' && $this->newNodeSelectedServerId) {
                $server = Server::findOrFail($this->newNodeSelectedServerId);
            } else {
                $privateKey = $this->newNodeSshKeyMode === 'generate'
                    ? $this->newNodeKeyPair['private']
                    : $this->newNodePrivateKey;

                $publicKey = $this->newNodeSshKeyMode === 'generate'
                    ? $this->newNodeKeyPair['public']
                    : '';

                $server = Server::create([
                    'name' => $this->newNodeName ?: "server-{$this->newNodeHost}",
                    'host' => $this->newNodeHost,
                    'ssh_port' => $this->newNodeSshPort,
                    'ssh_user' => $this->newNodeSshUser,
                    'ssh_private_key_encrypted' => $privateKey,
                    'ssh_public_key' => $publicKey,
                ]);
            }

            $nodeCount = $this->cluster->nodes()->count();

            $node = RedisNode::create([
                'server_id' => $server->id,
                'redis_cluster_id' => $this->cluster->id,
                'name' => $this->newNodeName ?: 'node-'.($nodeCount + 1)."-{$server->host}",
                'redis_port' => $this->newNodeRedisPort,
                'sentinel_port' => $this->newNodeSentinelPort,
                'role' => 'pending',
                'status' => 'unknown',
            ]);

            // Clear any previous progress for this node
            Cache::forget(AddRedisNodeJob::progressKey($node->id));

            // Dispatch the job
            AddRedisNodeJob::dispatch($this->cluster, $node);

            $this->addingNode = true;
            $this->addNodeComplete = false;
            $this->addingNodeId = $node->id;
            $this->addNodeSteps = [
                ['message' => "Starting provisioning for {$node->name}...", 'status' => 'running', 'time' => now()->format('H:i:s')],
            ];
            $this->showAddNode = false;

        } catch (\Throwable $e) { // @codeCoverageIgnore
            $this->setMessage("Error creating node: {$e->getMessage()}", 'error'); // @codeCoverageIgnore
        }
    }

    /**
     * Poll the add-node job progress from the cache.
     */
    public function pollAddNode(): void
    {
        if (! $this->addingNodeId) {
            return;
        }

        $progress = Cache::get(AddRedisNodeJob::progressKey($this->addingNodeId));

        if (! $progress) {
            return;
        }

        $this->addNodeSteps = $progress['steps'];

        if ($progress['status'] === 'complete') {
            $this->addNodeComplete = true;
            $this->addingNode = false;
            $this->cluster->refresh();
            $this->resetAddNodeForm();
        } elseif ($progress['status'] === 'failed') {
            $this->addingNode = false;
        }
    }

    /**
     * Dismiss the add-node progress panel.
     */
    public function dismissAddNodeProgress(): void
    {
        $this->addNodeSteps = [];
        $this->addNodeComplete = false;
        $this->addingNodeId = null;
        $this->cluster->refresh();
        $this->refreshStatus();
    }

    /**
     * Remove a node from the cluster.
     */
    public function removeNode(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);
        $name = $node->name;

        if ($node->isMaster()) {
            $this->setMessage('Cannot remove the master node. Trigger a failover first.', 'error');

            return;
        }

        $node->delete();

        $this->setMessage("Node {$name} removed.", 'success');
        $this->cluster->refresh();
    }

    // ─── Recovery & Maintenance Actions ────────────────────────────────

    /**
     * Trigger a Sentinel failover to promote a replica to master.
     */
    public function failover()
    {
        $master = $this->cluster->masterNode();

        if (! $master) {
            $this->setMessage('No master node available.', 'error');

            return;
        }

        try {
            $redisCliService = app(RedisCliService::class);
            $sentinelPassword = $this->cluster->sentinel_password_encrypted;
            $result = $redisCliService->sentinelFailover($master, $this->cluster->name, $sentinelPassword);

            if ($result['success']) {
                $this->setMessage('Sentinel failover initiated. Refresh status in a few seconds to see the new topology.', 'success');
                $this->refreshStatus();
            } else {
                $this->setMessage('Failover failed: '.($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Failover error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Restart the Redis server service on a node.
     */
    public function restartRedis(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);

        try {
            $ssh = app(SshService::class);
            $result = $ssh->exec($node, 'systemctl restart redis-server 2>&1', 'redis.restart', sudo: true);

            if ($result['success']) {
                $this->setMessage("Redis service restarted on {$node->name}.", 'success');
            } else {
                $this->setMessage("Failed to restart Redis on {$node->name}: ".($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error restarting Redis: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Restart the Sentinel service on a node.
     */
    public function restartSentinel(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);

        try {
            $ssh = app(SshService::class);
            $result = $ssh->exec($node, 'systemctl restart redis-sentinel 2>&1', 'sentinel.restart', sudo: true);

            if ($result['success']) {
                $this->setMessage("Sentinel service restarted on {$node->name}.", 'success');
            } else {
                $this->setMessage("Failed to restart Sentinel on {$node->name}: ".($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error restarting Sentinel: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Force a replica to re-sync with the master by issuing REPLICAOF.
     */
    public function forceResync(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);

        if ($node->isMaster()) {
            $this->setMessage('Cannot force resync on the master node.', 'error');

            return;
        }

        $master = $this->cluster->masterNode();

        if (! $master) {
            $this->setMessage('No master node found. Cannot resync.', 'error');

            return;
        }

        try {
            $redis = app(RedisCliService::class);
            $password = $this->cluster->auth_password_encrypted;

            // Issue REPLICAOF to re-establish replication
            $result = $redis->replicaOf($node, $master->server->host, $master->redis_port, $password);

            if ($result['success']) {
                $this->setMessage("Resync initiated on {$node->name}. A full sync will occur.", 'success');
            } else {
                $this->setMessage("Failed to resync {$node->name}: ".($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error forcing resync: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Reset Sentinel state to clear stale entries.
     */
    public function resetSentinel(): void
    {
        $anyNode = $this->cluster->nodes->first();

        if (! $anyNode) {
            $this->setMessage('No nodes available.', 'error');

            return;
        }

        try {
            $redis = app(RedisCliService::class);
            $sentinelPassword = $this->cluster->sentinel_password_encrypted;
            $result = $redis->sentinelReset($anyNode, $this->cluster->name, $sentinelPassword);

            if ($result['success']) {
                $this->setMessage('Sentinel state reset. Stale entries have been cleared.', 'success');
            } else {
                $this->setMessage('Failed to reset Sentinel: '.($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error resetting Sentinel: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Trigger BGSAVE on a node to create an RDB snapshot.
     */
    public function triggerBgsave(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);

        try {
            $redis = app(RedisCliService::class);
            $password = $this->cluster->auth_password_encrypted;
            $result = $redis->bgsave($node, $password);

            if ($result['success']) {
                $this->setMessage("BGSAVE initiated on {$node->name}.", 'success');
            } else {
                $this->setMessage("BGSAVE failed on {$node->name}: ".($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Trigger AOF rewrite on a node.
     */
    public function triggerAofRewrite(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);

        try {
            $redis = app(RedisCliService::class);
            $password = $this->cluster->auth_password_encrypted;
            $result = $redis->bgrewriteaof($node, $password);

            if ($result['success']) {
                $this->setMessage("AOF rewrite initiated on {$node->name}.", 'success');
            } else {
                $this->setMessage("AOF rewrite failed on {$node->name}: ".($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Purge unused memory on a node.
     */
    public function memoryPurge(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);

        try {
            $redis = app(RedisCliService::class);
            $password = $this->cluster->auth_password_encrypted;
            $result = $redis->memoryPurge($node, $password);

            if ($result['success']) {
                $this->setMessage("Memory purged on {$node->name}.", 'success');
            } else {
                $this->setMessage("Memory purge failed on {$node->name}: ".($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Flush Sentinel config to disk on all nodes.
     */
    public function flushSentinelConfig(): void
    {
        $redis = app(RedisCliService::class);
        $sentinelPassword = $this->cluster->sentinel_password_encrypted;
        $errors = [];

        foreach ($this->cluster->nodes as $node) {
            try {
                $result = $redis->sentinelFlushConfig($node, $sentinelPassword);
                if (! $result['success']) {
                    $errors[] = "{$node->name}: ".($result['output'] ?? 'Unknown error');
                }
            } catch (\Throwable $e) {
                $errors[] = "{$node->name}: {$e->getMessage()}";
            }
        }

        if (empty($errors)) {
            $this->setMessage('Sentinel config flushed to disk on all nodes.', 'success');
        } else {
            $this->setMessage('Some nodes failed: '.implode('; ', $errors), 'error');
        }
    }

    // ─── Firewall ───────────────────────────────────────────────────────

    /**
     * Toggle the firewall management panel for a node.
     */
    public function toggleFirewall(int $nodeId): void
    {
        if ($this->firewallNodeId === $nodeId) {
            $this->firewallNodeId = null;
            $this->showFirewallModal = false;
            $this->firewallRules = [];
            $this->firewallOutput = '';

            return;
        }

        $this->firewallNodeId = $nodeId;
        $this->showFirewallModal = true;
        $this->firewallNewIp = '';
        $this->firewallOutput = '';
        $this->loadFirewallRules($nodeId);
    }

    /**
     * Load current UFW rules for Redis/Sentinel ports.
     */
    protected function loadFirewallRules(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);
        $sshService = app(SshService::class);

        try {
            $result = $sshService->exec(
                $node,
                'ufw status numbered 2>&1',
                'firewall.status',
                sudo: true
            );

            $rules = [];
            if ($result['success']) {
                foreach (explode("\n", $result['output']) as $line) {
                    if (preg_match('/(?:6379|26379)/', $line) && preg_match('/ALLOW/', $line)) {
                        if (preg_match('/\[\s*(\d+)\]\s+(.+)/', $line, $m)) {
                            $rules[] = [
                                'number' => (int) $m[1],
                                'rule' => trim($m[2]),
                            ];
                        }
                    }
                }
                $this->firewallOutput = $result['output'];
            }

            $this->firewallRules = $rules;
        } catch (\Throwable $e) {
            $this->firewallRules = [];
            $this->firewallOutput = '';
            $this->setMessage("Could not load firewall rules: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Configure firewall rules for a node (add an IP/CIDR for Redis and Sentinel ports).
     */
    public function addFirewallRule(): void
    {
        $this->validate([
            'firewallNewIp' => 'required|string',
        ]);

        $node = RedisNode::findOrFail($this->firewallNodeId);
        $sshService = app(SshService::class);

        try {
            $ip = trim($this->firewallNewIp);
            $redisPort = $node->redis_port;
            $sentinelPort = $node->sentinel_port;

            $result = $sshService->exec(
                $node,
                "ufw allow from {$ip} to any port {$redisPort} proto tcp comment 'Redis from {$ip}' && ".
                "ufw allow from {$ip} to any port {$sentinelPort} proto tcp comment 'Sentinel from {$ip}'",
                'firewall.add_redis_rule',
                sudo: true
            );

            if ($result['success']) {
                $this->setMessage("Firewall rule added: {$ip} -> ports {$redisPort}, {$sentinelPort}", 'success');
                $this->firewallNewIp = '';
                $this->loadFirewallRules($this->firewallNodeId);
            } else {
                $this->setMessage('Failed to add firewall rule: '.($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    // ─── Rename ─────────────────────────────────────────────────────────

    /**
     * Start renaming a node.
     */
    public function startRename(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);
        $this->renamingNodeId = $nodeId;
        $this->renameNodeValue = $node->name;
    }

    /**
     * Save the new node name.
     */
    public function saveRename(): void
    {
        if (! $this->renamingNodeId) {
            return;
        }

        $this->validate([
            'renameValue' => 'required|string|max:255',
        ]);

        $node = RedisNode::findOrFail($this->renamingNodeId);
        $node->update(['name' => $this->renameNodeValue]);

        $this->renamingNodeId = null;
        $this->renameNodeValue = '';
        $this->cluster->refresh();
    }

    /**
     * Cancel renaming.
     */
    public function cancelRename(): void
    {
        $this->renamingNodeId = null;
        $this->renameNodeValue = '';
    }

    // ─── SSH Test ───────────────────────────────────────────────────────

    /**
     * Test SSH connection to the new node being added.
     */
    public function testSsh(): void
    {
        $privateKey = $this->newNodeSshKeyMode === 'generate'
            ? ($this->newNodeKeyPair['private'] ?? '')
            : $this->newNodePrivateKey;

        if (empty($privateKey)) {
            $this->sshTestResult = 'failed';

            return;
        }

        try {
            $sshService = app(SshService::class);
            $result = $sshService->testConnectionDirect(
                $this->newNodeHost,
                $this->newNodeSshPort,
                $this->newNodeSshUser,
                $privateKey,
            );

            $this->sshTestResult = $result['success'] ? 'success' : 'failed';
        } catch (\Throwable $e) {
            $this->sshTestResult = 'failed';
        }
    }

    // ─── Firewall Actions ───────────────────────────────────────────────

    /**
     * Remove a specific firewall rule by number.
     */
    public function removeFirewallRule(int $ruleNumber): void
    {
        $node = RedisNode::findOrFail($this->firewallNodeId);
        $sshService = app(SshService::class);

        try {
            $result = $sshService->exec(
                $node,
                "ufw --force delete {$ruleNumber} 2>&1",
                'firewall.delete_rule',
                sudo: true
            );

            if ($result['success']) {
                $this->setMessage('Firewall rule removed.', 'success');
                $this->loadFirewallRules($this->firewallNodeId);
            } else {
                $this->setMessage('Failed to remove rule: '.($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Configure all cluster nodes' firewalls to allow each other.
     */
    public function configureAllFirewallRules(): void
    {
        try {
            $firewallService = app(FirewallService::class);
            $node = RedisNode::findOrFail($this->firewallNodeId);
            $firewallService->configureRedisNode($node, $this->cluster);

            $this->setMessage('Firewall configured with all cluster node IPs.', 'success');
            $this->loadFirewallRules($this->firewallNodeId);
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Retry adding a node that failed provisioning.
     */
    public function retryAddNode(int $nodeId): void
    {
        $node = RedisNode::findOrFail($nodeId);

        // Clear any previous progress
        Cache::forget(AddRedisNodeJob::progressKey($node->id));

        // Reset node status
        $node->update(['status' => 'unknown', 'role' => 'pending']);

        // Dispatch the job
        AddRedisNodeJob::dispatch($this->cluster, $node);

        $this->addingNode = true;
        $this->addNodeComplete = false;
        $this->addingNodeId = $node->id;
        $this->addNodeSteps = [
            ['message' => "Retrying provisioning for {$node->name}...", 'status' => 'running', 'time' => now()->format('H:i:s')],
        ];
    }

    // ─── Cluster Actions ────────────────────────────────────────────────

    /**
     * Re-provision: redirect to the setup wizard pre-loaded with this cluster's details.
     */
    public function reprovision()
    {
        return $this->redirect(route('redis.reprovision', $this->cluster), navigate: true);
    }

    /**
     * Delete a cluster and all its nodes.
     */
    public function deleteCluster()
    {
        $name = $this->cluster->name;
        $this->cluster->nodes()->delete();
        $this->cluster->auditLogs()->delete();
        $this->cluster->delete();

        session()->flash('message', "Redis cluster '{$name}' deleted.");

        return redirect()->route('dashboard');
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    protected function setMessage(string $message, string $status): void
    {
        $this->actionMessage = $message;
        $this->actionStatus = $status;
    }

    protected function resetAddNodeForm(): void
    {
        $this->showAddNode = false;
        $this->newNodeServerMode = 'new';
        $this->newNodeSelectedServerId = null;
        $this->newNodeHost = '';
        $this->newNodeName = '';
        $this->newNodeSshPort = 22;
        $this->newNodeSshUser = 'root';
        $this->newNodeRedisPort = 6379;
        $this->newNodeSentinelPort = 26379;
        $this->newNodeKeyPair = null;
        $this->newNodePrivateKey = '';
        $this->sshTestResult = '';
    }

    public function render()
    {
        // Exclude servers already used as Redis nodes in this cluster (by ID and by host IP)
        $usedServerIds = $this->cluster->nodes()->pluck('server_id');
        $usedHosts = Server::whereIn('id', $usedServerIds)->pluck('host');

        $availableServers = Server::whereNotNull('ssh_private_key_encrypted')
            ->whereNotIn('id', $usedServerIds)
            ->whereNotIn('host', $usedHosts)
            ->orderBy('name')
            ->get();

        return view('livewire.redis-cluster-manager', [
            'availableServers' => $availableServers,
        ])->layout('layouts.app', ['title' => $this->cluster->name]);
    }
}
