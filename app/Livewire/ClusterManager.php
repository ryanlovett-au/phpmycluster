<?php

namespace App\Livewire;

use App\Jobs\AddNodeJob;
use App\Jobs\RefreshDbStatusJob;
use App\Jobs\RefreshRouterStatusJob;
use App\Jobs\RefreshUserListJob;
use App\Jobs\SetupRouterJob;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Models\Server;
use App\Services\MysqlProvisionService;
use App\Services\MysqlShellService;
use App\Services\SshService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ClusterManager extends Component
{
    public MysqlCluster $cluster;

    // Add DB node form
    public bool $showAddNode = false;

    public string $newNodeHost = '';

    public string $newNodeName = '';

    public int $newNodeSshPort = 22;

    public string $newNodeSshUser = 'root';

    public string $newNodeSshKeyMode = 'generate';

    public string $newNodePrivateKey = '';

    public ?array $newNodeKeyPair = null;

    // Add DB node provisioning progress
    public bool $addingNode = false;

    public bool $addNodeComplete = false;

    public ?int $addingNodeId = null;

    public array $addNodeSteps = [];

    // Add router form
    public bool $showAddRouter = false;

    public string $routerHost = '';

    public string $routerName = '';

    public int $routerSshPort = 22;

    public string $routerSshUser = 'root';

    public string $routerSshKeyMode = 'generate';

    public string $routerPrivateKey = '';

    public ?array $routerKeyPair = null;

    public string $routerAllowFrom = '127.0.0.1';

    // Router setup progress
    public bool $settingUpRouter = false;

    public bool $setupRouterComplete = false;

    public ?int $settingUpRouterId = null;

    public array $setupRouterSteps = [];

    // Router firewall
    public ?int $firewallRouterId = null;

    public string $firewallNewIp = '';

    public array $firewallRules = [];

    // User management
    public array $mysqlUsers = [];

    public array $databases = [];

    public bool $showUserModal = false;

    public bool $editingUser = false;

    public string $userFormUsername = '';

    public string $userFormPassword = '';

    public string $userFormHost = '%';

    public string $userFormDatabase = '*';

    public string $userFormPreset = 'readwrite';

    public bool $userFormCreateDb = true;

    public string $editingUserOriginal = ''; // "user@host" for edit mode

    public string $userFormError = '';

    // Rename node
    public ?int $renamingNodeId = null;

    public string $renameNodeValue = '';

    // Status
    public ?array $clusterStatus = null;

    public bool $refreshing = false;

    public ?string $refreshBatchId = null;

    // Action feedback
    public string $actionMessage = '';

    public string $actionStatus = ''; // success, error, info

    public function mount(MysqlCluster $cluster)
    {
        $this->cluster = $cluster->load('nodes');

        // Load cached data if available
        $cachedStatus = Cache::get("cluster_status_{$cluster->id}");
        if ($cachedStatus) {
            $this->clusterStatus = $cachedStatus;
        }

        $cachedUsers = Cache::get("cluster_users_{$cluster->id}");
        if (is_array($cachedUsers)) {
            $this->mysqlUsers = $cachedUsers;
        }

        $cachedDbs = Cache::get("cluster_databases_{$cluster->id}");
        if (is_array($cachedDbs)) {
            $this->databases = $cachedDbs;
        }
    }

    // ─── Cluster Status ─────────────────────────────────────────────────

    /**
     * Refresh cluster status by dispatching parallel jobs for DB status, router checks, and user list.
     */
    public function refreshStatus()
    {
        $this->refreshing = true;

        $jobs = [];

        // Job 1: Check DB cluster status (tries primary first, falls back to secondaries)
        $jobs[] = new RefreshDbStatusJob($this->cluster);

        // Job 2+: Check each router node in parallel
        $routerNodes = $this->cluster->nodes()->where('role', 'access')->get();
        foreach ($routerNodes as $routerNode) {
            $jobs[] = new RefreshRouterStatusJob($routerNode);
        }

        // Job N: Refresh user list and database list
        $jobs[] = new RefreshUserListJob($this->cluster);

        try {
            $batch = Bus::batch($jobs)
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
            // Reload data from DB and cache
            $this->cluster->refresh();

            $cachedStatus = Cache::get("cluster_status_{$this->cluster->id}");
            if ($cachedStatus) {
                $this->clusterStatus = $cachedStatus;
            }

            $cachedUsers = Cache::get("cluster_users_{$this->cluster->id}");
            if (is_array($cachedUsers)) {
                $this->mysqlUsers = $cachedUsers;
            }

            $cachedDbs = Cache::get("cluster_databases_{$this->cluster->id}");
            if (is_array($cachedDbs)) {
                $this->databases = $cachedDbs;
            }

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

    // ─── Add DB Node ────────────────────────────────────────────────────

    /**
     * Generate SSH key for new DB node.
     */
    public function generateNewNodeKey()
    {
        $this->newNodeKeyPair = app(SshService::class)->generateKeyPair();
    }

    /**
     * Add a new DB node to the cluster — dispatches a background job.
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
            // Determine mysql_server_id
            $maxServerId = $this->cluster->nodes()->max('mysql_server_id') ?? 0;

            $server = Server::create([
                'name' => $this->newNodeName ?: "server-{$this->newNodeHost}",
                'host' => $this->newNodeHost,
                'ssh_port' => $this->newNodeSshPort,
                'ssh_user' => $this->newNodeSshUser,
                'ssh_private_key_encrypted' => $privateKey,
                'ssh_public_key' => $publicKey,
            ]);

            $node = MysqlNode::create([
                'server_id' => $server->id,
                'cluster_id' => $this->cluster->id,
                'name' => $this->newNodeName ?: 'node-'.($maxServerId + 1)."-{$this->newNodeHost}",
                'role' => 'pending',
                'status' => 'unknown',
                'mysql_server_id' => $maxServerId + 1,
            ]);

            // Clear any previous progress for this node
            Cache::forget(AddNodeJob::progressKey($node->id));

            // Dispatch the job
            AddNodeJob::dispatch($this->cluster, $node);

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

        $progress = Cache::get(AddNodeJob::progressKey($this->addingNodeId));

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
     * Retry provisioning a failed/pending DB node.
     */
    public function retryAddNode(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);

        // Clear any previous progress
        Cache::forget(AddNodeJob::progressKey($node->id));

        // Reset node status
        $node->update(['status' => 'unknown', 'role' => 'pending']);

        // Dispatch the job
        AddNodeJob::dispatch($this->cluster, $node);

        $this->addingNode = true;
        $this->addNodeComplete = false;
        $this->addingNodeId = $node->id;
        $this->addNodeSteps = [
            ['message' => "Starting provisioning for {$node->name}...", 'status' => 'running', 'time' => now()->format('H:i:s')],
        ];
    }

    /**
     * Delete a pending/failed node that was never added to the cluster.
     */
    public function deleteNode(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);

        if ($node->role->value !== 'pending') {
            $this->setMessage('Cannot delete an active node. Remove it from the cluster first.', 'error');

            return;
        }

        Cache::forget(AddNodeJob::progressKey($node->id));
        $name = $node->name;
        $node->delete();

        $this->setMessage("Node {$name} deleted.", 'success');
        $this->cluster->refresh();
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

    // ─── Add Router ─────────────────────────────────────────────────────

    /**
     * Generate SSH key for new router node.
     */
    public function generateRouterKey()
    {
        $this->routerKeyPair = app(SshService::class)->generateKeyPair();
    }

    /**
     * Set up MySQL Router on a new access node — dispatches a background job.
     */
    public function setupRouter()
    {
        $this->validate([
            'routerHost' => 'required|string',
        ]);

        $privateKey = $this->routerSshKeyMode === 'generate'
            ? $this->routerKeyPair['private']
            : $this->routerPrivateKey;

        $publicKey = $this->routerSshKeyMode === 'generate'
            ? $this->routerKeyPair['public']
            : '';

        try {
            $server = Server::create([
                'name' => $this->routerName ?: "server-{$this->routerHost}",
                'host' => $this->routerHost,
                'ssh_port' => $this->routerSshPort,
                'ssh_user' => $this->routerSshUser,
                'ssh_private_key_encrypted' => $privateKey,
                'ssh_public_key' => $publicKey,
            ]);

            $node = MysqlNode::create([
                'server_id' => $server->id,
                'cluster_id' => $this->cluster->id,
                'name' => $this->routerName ?: "router-{$this->routerHost}",
                'role' => 'access',
                'status' => 'unknown',
            ]);

            // Clear any previous progress
            Cache::forget(SetupRouterJob::progressKey($node->id));

            // Dispatch the job
            SetupRouterJob::dispatch($this->cluster, $node, $this->routerAllowFrom);

            $this->settingUpRouter = true;
            $this->setupRouterComplete = false;
            $this->settingUpRouterId = $node->id;
            $this->setupRouterSteps = [
                ['message' => "Starting router setup for {$node->name}...", 'status' => 'running', 'time' => now()->format('H:i:s')],
            ];
            $this->showAddRouter = false;

        } catch (\Throwable $e) { // @codeCoverageIgnore
            $this->setMessage("Error creating router node: {$e->getMessage()}", 'error'); // @codeCoverageIgnore
        }
    }

    /**
     * Poll the router setup job progress from the cache.
     */
    public function pollSetupRouter(): void
    {
        if (! $this->settingUpRouterId) {
            return;
        }

        $progress = Cache::get(SetupRouterJob::progressKey($this->settingUpRouterId));

        if (! $progress) {
            return;
        }

        $this->setupRouterSteps = $progress['steps'];

        if ($progress['status'] === 'complete') {
            $this->setupRouterComplete = true;
            $this->settingUpRouter = false;
            $this->cluster->refresh();
            $this->resetRouterForm();
        } elseif ($progress['status'] === 'failed') {
            $this->settingUpRouter = false;
        }
    }

    /**
     * Retry setting up a failed router node.
     */
    public function retrySetupRouter(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);

        Cache::forget(SetupRouterJob::progressKey($node->id));
        $node->update(['status' => 'unknown']);

        SetupRouterJob::dispatch($this->cluster, $node, $this->routerAllowFrom);

        $this->settingUpRouter = true;
        $this->setupRouterComplete = false;
        $this->settingUpRouterId = $node->id;
        $this->setupRouterSteps = [
            ['message' => "Retrying router setup for {$node->name}...", 'status' => 'running', 'time' => now()->format('H:i:s')],
        ];
    }

    /**
     * Dismiss the router setup progress panel.
     */
    public function dismissRouterProgress(): void
    {
        $this->setupRouterSteps = [];
        $this->setupRouterComplete = false;
        $this->settingUpRouterId = null;
        $this->cluster->refresh();
    }

    /**
     * Delete a failed/offline router node.
     */
    public function deleteRouter(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);

        if ($node->status->value === 'online') {
            $this->setMessage('Cannot delete a running router. Stop it first.', 'error');

            return;
        }

        Cache::forget(SetupRouterJob::progressKey($node->id));
        $name = $node->name;
        $node->delete();

        $this->setMessage("Router {$name} deleted.", 'success');
        $this->cluster->refresh();
    }

    /**
     * Check status of a single router node.
     */
    public function checkRouterStatus(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);
        $result = app(MysqlProvisionService::class)->getRouterStatus($node);

        $node->update([
            'status' => $result['running'] ? 'online' : 'offline',
            'last_checked_at' => now(),
        ]);

        $this->cluster->refresh();
        $this->setMessage(
            $result['running'] ? "Router on {$node->name} is running." : "Router on {$node->name} is not running.",
            $result['running'] ? 'success' : 'error'
        );
    }

    // ─── Router Firewall ───────────────────────────────────────────────

    /**
     * Toggle the firewall management panel for a router.
     */
    public function toggleFirewall(int $nodeId): void
    {
        if ($this->firewallRouterId === $nodeId) {
            $this->firewallRouterId = null;
            $this->firewallRules = [];

            return;
        }

        $this->firewallRouterId = $nodeId;
        $this->firewallNewIp = '';
        $this->loadFirewallRules($nodeId);
    }

    /**
     * Load current UFW rules for router ports (6446/6447).
     */
    protected function loadFirewallRules(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);
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
                    if (preg_match('/644[67]/', $line) && preg_match('/ALLOW/', $line)) {
                        // Extract rule number and details
                        if (preg_match('/\[\s*(\d+)\]\s+(.+)/', $line, $m)) {
                            $rules[] = [
                                'number' => (int) $m[1],
                                'rule' => trim($m[2]),
                            ];
                        }
                    }
                }
            }

            $this->firewallRules = $rules;
        } catch (\Throwable $e) {
            $this->firewallRules = [];
            $this->setMessage("Could not load firewall rules: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Add an IP/CIDR to the router's UFW rules (ports 6446 and 6447).
     */
    public function addFirewallRule(): void
    {
        $this->validate([
            'firewallNewIp' => 'required|string',
        ]);

        $node = MysqlNode::findOrFail($this->firewallRouterId);
        $sshService = app(SshService::class);

        try {
            $ip = trim($this->firewallNewIp);
            $result = $sshService->exec(
                $node,
                "ufw allow from {$ip} to any port 6446 proto tcp comment 'MySQL Router R/W from {$ip}' && ".
                "ufw allow from {$ip} to any port 6447 proto tcp comment 'MySQL Router R/O from {$ip}'",
                'firewall.add_router_rule',
                sudo: true
            );

            if ($result['success']) {
                $this->setMessage("Firewall rule added: {$ip} → ports 6446, 6447", 'success');
                $this->firewallNewIp = '';
                $this->loadFirewallRules($this->firewallRouterId);
            } else {
                $this->setMessage('Failed to add firewall rule: '.($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Remove a UFW rule by its rule number.
     */
    public function removeFirewallRule(int $ruleNumber): void
    {
        if (! $this->firewallRouterId) {
            return;
        }

        $node = MysqlNode::findOrFail($this->firewallRouterId);
        $sshService = app(SshService::class);

        try {
            $result = $sshService->exec(
                $node,
                "yes | ufw delete {$ruleNumber} 2>&1",
                'firewall.delete_rule',
                sudo: true
            );

            if ($result['success']) {
                $this->setMessage('Firewall rule removed.', 'success');
                $this->loadFirewallRules($this->firewallRouterId);
            } else {
                $this->setMessage('Failed to remove rule: '.($result['output'] ?? 'Unknown error'), 'error');
            }
        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    // ─── DB Node Cluster Actions ────────────────────────────────────────

    /**
     * Remove a DB node from the cluster.
     */
    public function removeNode(int $nodeId, bool $force = false)
    {
        $node = MysqlNode::findOrFail($nodeId);
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
        $node = MysqlNode::findOrFail($nodeId);
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

    // ─── Recovery Actions ───────────────────────────────────────────────

    /**
     * Force quorum when majority is lost.
     */
    public function forceQuorum(int $nodeId)
    {
        $node = MysqlNode::findOrFail($nodeId);
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
        $node = MysqlNode::findOrFail($nodeId);
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
     * Re-provision: redirect to the setup wizard pre-loaded with this cluster's details.
     */
    public function reprovision()
    {
        return $this->redirect(route('cluster.reprovision', $this->cluster), navigate: true);
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

        session()->flash('message', "Cluster '{$name}' deleted.");

        return redirect()->route('dashboard');
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

    // ─── Node Status ────────────────────────────────────────────────────

    // ─── User Management ──────────────────────────────────────────────

    /**
     * Load the list of MySQL users and databases from the primary node.
     * This runs synchronously (used after user create/edit/drop for immediate feedback).
     */
    public function loadUsers(): void
    {
        $primary = $this->cluster->primaryNode();
        if (! $primary) {
            return;
        }

        $mysqlShell = app(MysqlShellService::class);
        $password = $this->cluster->cluster_admin_password_encrypted;

        $usersResult = $mysqlShell->listUsers($primary, $password);
        if ($usersResult['success'] && is_array($usersResult['data']) && ! isset($usersResult['data']['error'])) {
            $this->mysqlUsers = $usersResult['data'];
            Cache::put("cluster_users_{$this->cluster->id}", $usersResult['data'], now()->addMinutes(10));
        }

        $dbResult = $mysqlShell->listDatabases($primary, $password);
        if ($dbResult['success'] && is_array($dbResult['data']) && ! isset($dbResult['data']['error'])) {
            $this->databases = $dbResult['data'];
            Cache::put("cluster_databases_{$this->cluster->id}", $dbResult['data'], now()->addMinutes(10));
        }
    }

    /**
     * Open the add user modal.
     */
    public function openAddUser(): void
    {
        $this->resetUserForm();
        $this->editingUser = false;
        $this->showUserModal = true;
        $this->loadUsers();
    }

    /**
     * Open the edit user modal.
     */
    public function openEditUser(string $user, string $host): void
    {
        $this->resetUserForm();
        $this->editingUser = true;
        $this->editingUserOriginal = "{$user}@{$host}";
        $this->userFormUsername = $user;
        $this->userFormHost = $host;
        $this->userFormPassword = '';
        $this->showUserModal = true;

        // Load databases for the dropdown
        $primary = $this->cluster->primaryNode();
        if ($primary) {
            $dbResult = app(MysqlShellService::class)->listDatabases($primary, $this->cluster->cluster_admin_password_encrypted);
            if ($dbResult['success'] && is_array($dbResult['data']) && ! isset($dbResult['data']['error'])) {
                $this->databases = $dbResult['data'];
            }
        }
    }

    /**
     * Save the user (create or update).
     */
    public function saveUser(): void
    {
        $this->userFormError = '';

        $this->validate([
            'userFormUsername' => 'required|string|max:32',
            'userFormHost' => 'required|string|max:255',
            'userFormDatabase' => 'required|string',
            'userFormPreset' => 'required|string|in:readonly,readwrite,admin',
        ]);

        if (! $this->editingUser) {
            $this->validate([
                'userFormPassword' => 'required|string|min:8',
            ]);
        }

        $primary = $this->cluster->primaryNode();
        if (! $primary) {
            $this->userFormError = 'No primary node available.';

            return;
        }

        $mysqlShell = app(MysqlShellService::class);
        $password = $this->cluster->cluster_admin_password_encrypted;

        $privileges = match ($this->userFormPreset) {
            'readonly' => 'SELECT',
            'readwrite' => 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES',
            'admin' => 'ALL PRIVILEGES',
            default => 'SELECT',
        };

        // If creating a new user with "create database" checked, use the username as the database name
        if (! $this->editingUser && $this->userFormCreateDb) {
            $this->userFormDatabase = $this->userFormUsername;

            // Create the database first
            $createDbResult = $mysqlShell->createDatabase($primary, $password, $this->userFormUsername);
            if (isset($createDbResult['data']['error'])) {
                $this->userFormError = 'Failed to create database: '.$createDbResult['data']['error'];

                return;
            }

            // Grant all privileges on the new database
            $privileges = 'ALL PRIVILEGES';
        }

        if ($this->editingUser) {
            $result = $mysqlShell->updateUser(
                $primary,
                $password,
                $this->userFormUsername,
                $this->userFormHost,
                $this->userFormPassword ?: null,
                $this->userFormDatabase,
                $privileges
            );
        } else {
            $result = $mysqlShell->createUser(
                $primary,
                $password,
                $this->userFormUsername,
                $this->userFormPassword,
                $this->userFormHost,
                $this->userFormDatabase,
                $privileges
            );
        }

        if ($result['success'] && ! isset($result['data']['error'])) {
            $action = $this->editingUser ? 'updated' : 'created';
            $this->setMessage("User '{$this->userFormUsername}'@'{$this->userFormHost}' {$action}.", 'success');
            $this->dispatch('close-modal', name: 'user-modal');
            $this->loadUsers();
        } else {
            $this->userFormError = $result['data']['error'] ?? $result['raw_output'] ?? 'Unknown error';
        }
    }

    /**
     * Drop a MySQL user.
     */
    public function dropUser(string $user, string $host): void
    {
        $primary = $this->cluster->primaryNode();
        if (! $primary) {
            $this->setMessage('No primary node available.', 'error');

            return;
        }

        $result = app(MysqlShellService::class)->dropUser(
            $primary,
            $this->cluster->cluster_admin_password_encrypted,
            $user,
            $host
        );

        if ($result['success'] && ! isset($result['data']['error'])) {
            $this->setMessage("User '{$user}'@'{$host}' dropped.", 'success');
            $this->loadUsers();
        } else {
            $this->setMessage('Failed to drop user: '.($result['data']['error'] ?? $result['raw_output']), 'error');
        }
    }

    /**
     * Close the user modal.
     */
    public function closeUserModal(): void
    {
        $this->showUserModal = false;
        $this->resetUserForm();
    }

    /**
     * Reset the user form fields.
     */
    protected function resetUserForm(): void
    {
        $this->userFormUsername = '';
        $this->userFormPassword = '';
        $this->userFormHost = '%';
        $this->userFormDatabase = '*';
        $this->userFormPreset = 'readwrite';
        $this->userFormCreateDb = true;
        $this->editingUser = false;
        $this->editingUserOriginal = '';
        $this->userFormError = '';
    }

    // ─── Rename ─────────────────────────────────────────────────────────

    /**
     * Start renaming a node.
     */
    public function startRename(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);
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
            'renameNodeValue' => 'required|string|max:255',
        ]);

        $node = MysqlNode::findOrFail($this->renamingNodeId);
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

    // ─── Helpers ────────────────────────────────────────────────────────

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

    protected function resetRouterForm(): void
    {
        $this->showAddRouter = false;
        $this->routerHost = '';
        $this->routerName = '';
        $this->routerSshPort = 22;
        $this->routerSshUser = 'root';
        $this->routerKeyPair = null;
        $this->routerPrivateKey = '';
    }

    public function render()
    {
        $allNodes = $this->cluster->nodes()->get();

        return view('livewire.cluster-manager', [
            'nodes' => $allNodes->filter(fn ($n) => $n->role->value !== 'access'),
            'routerNodes' => $allNodes->filter(fn ($n) => $n->role->value === 'access'),
        ])->layout('layouts.app', ['title' => $this->cluster->name]);
    }
}
