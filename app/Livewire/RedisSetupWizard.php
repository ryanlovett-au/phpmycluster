<?php

namespace App\Livewire;

use App\Jobs\ProvisionRedisClusterJob;
use App\Models\RedisCluster;
use App\Models\RedisNode;
use App\Models\Server;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Validate;
use Livewire\Component;

class RedisSetupWizard extends Component
{
    public int $step = 1;

    // Step 1: Cluster details
    #[Validate('required|string|max:255|unique:redis_clusters,name')]
    public string $clusterName = '';

    #[Validate('required|string|min:12')]
    public string $authPassword = '';

    #[Validate('required|string|min:12')]
    public string $sentinelPassword = '';

    public int $quorum = 2;

    // Step 2: Server selection
    public string $serverMode = 'new'; // new or existing

    public ?int $selectedServerId = null;

    // Master node details (for new server)
    #[Validate('required|string')]
    public string $masterHost = '';

    public int $masterSshPort = 22;

    public string $masterSshUser = 'root';

    public int $masterRedisPort = 6379;

    public int $masterSentinelPort = 26379;

    public string $masterNodeName = '';

    // SSH key handling
    public string $sshKeyMode = 'generate'; // generate or existing

    public string $existingPrivateKey = '';

    public ?array $generatedKeyPair = null;

    public bool $publicKeyCopied = false;

    // Step progress tracking
    public array $provisionSteps = [];

    public string $currentAction = '';

    public bool $provisioning = false;

    public bool $provisioningComplete = false;

    // Re-provisioning mode
    public bool $isReprovision = false;

    public bool $sshKeyMissing = false;

    public bool $sshKeyAuthFailed = false;

    // Results
    public ?int $clusterId = null;

    public function mount(?RedisCluster $cluster = null): void
    {
        if ($cluster && $cluster->exists) {
            $this->isReprovision = true;
            $this->clusterId = $cluster->id;
            $this->clusterName = $cluster->name;
            $this->authPassword = $cluster->auth_password_encrypted;
            $this->sentinelPassword = $cluster->sentinel_password_encrypted;
            $this->quorum = $cluster->quorum;

            $node = $cluster->nodes()->first();
            if ($node) {
                $this->masterNodeName = $node->name;
                $this->masterHost = $node->server->host;
                $this->masterSshPort = $node->server->ssh_port;
                $this->masterSshUser = $node->server->ssh_user;
                $this->masterRedisPort = $node->redis_port;
                $this->masterSentinelPort = $node->sentinel_port;

                // Test if existing SSH key still works
                if (empty($node->server->ssh_private_key_encrypted)) {
                    // Key not stored in the database
                    $this->sshKeyMissing = true;
                    $this->sshKeyMode = 'generate';
                } else {
                    $sshService = app(SshService::class);
                    try {
                        $result = $sshService->testConnection($node->server);
                        if ($result['success']) {
                            // Key works — skip straight to provision
                            $this->sshKeyMode = 'existing';
                            $this->step = 4;

                            return;
                        }
                        Log::warning('SSH key test failed during re-provision mount', [
                            'node_id' => $node->id,
                            'result' => $result,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('SSH key test exception during re-provision mount', [
                            'node_id' => $node->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Key exists in DB but auth failed on the server
                    $this->sshKeyAuthFailed = true;
                    $this->sshKeyMode = 'generate';
                }
            }

            // SSH key needs setup — start at step 3
            $this->step = 3;
        }
    }

    public function nextStep()
    {
        if ($this->step === 1) {
            $uniqueRule = $this->isReprovision
                ? "required|string|max:255|unique:redis_clusters,name,{$this->clusterId}"
                : 'required|string|max:255|unique:redis_clusters,name';

            $this->validate([
                'clusterName' => $uniqueRule,
                'authPassword' => 'required|string|min:12',
                'sentinelPassword' => 'nullable|string|min:12',
            ]);
        }

        if ($this->step === 2) {
            if ($this->serverMode === 'existing') {
                $this->validate([
                    'selectedServerId' => 'required|exists:servers,id',
                ], [
                    'selectedServerId.required' => 'Please select a server.',
                ]);

                // Populate fields from existing server for the summary
                $server = Server::findOrFail($this->selectedServerId);
                $this->masterHost = $server->host;
                $this->masterSshPort = $server->ssh_port;
                $this->masterSshUser = $server->ssh_user;

                // Skip SSH key step (step 3) — go straight to provision
                $this->step = 4;

                return;
            }

            $this->validate([
                'masterHost' => 'required|string',
            ]);
        }

        $this->step++;
    }

    public function previousStep()
    {
        // If going back from provision step and using existing server, skip SSH key step
        if ($this->step === 4 && $this->serverMode === 'existing') {
            $this->step = 2;

            return;
        }

        $this->step = max(1, $this->step - 1);
    }

    /**
     * Generate an SSH keypair for the master node.
     */
    public function generateSshKey()
    {
        $sshService = app(SshService::class);
        $this->generatedKeyPair = $sshService->generateKeyPair();
    }

    /**
     * Test SSH connectivity to the master node.
     */
    public function testSshConnection()
    {
        $this->provisionSteps = [];
        $this->parseHostField();
        $this->addProvisionStep("Testing SSH connection to {$this->masterSshUser}@{$this->masterHost}:{$this->masterSshPort}...");

        $privateKey = $this->sshKeyMode === 'generate'
            ? $this->generatedKeyPair['private']
            : $this->existingPrivateKey;

        try {
            $sshService = app(SshService::class);
            $result = $sshService->testConnectionDirect(
                $this->masterHost,
                $this->masterSshPort,
                $this->masterSshUser,
                $privateKey,
            );

            if ($result['success']) {
                $this->updateLastProvisionStep("Connected! Hostname: {$result['hostname']}, OS: {$result['os']}", 'success');
            } else {
                $this->updateLastProvisionStep("Connection failed: {$result['error']}", 'error');
            }

            return $result;
        } catch (\Throwable $e) {
            $this->updateLastProvisionStep("Connection failed: {$e->getMessage()}", 'error');

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Parse user@host format from the host field if provided.
     */
    protected function parseHostField(): void
    {
        if (str_contains($this->masterHost, '@')) {
            [$user, $host] = explode('@', $this->masterHost, 2);
            $this->masterSshUser = $user;
            $this->masterHost = $host;
        }
    }

    /**
     * Dispatch the provisioning job to the queue.
     */
    public function startProvision()
    {
        $this->provisioning = true;
        $this->provisionSteps = [];

        $privateKey = $this->sshKeyMode === 'generate'
            ? ($this->generatedKeyPair['private'] ?? null)
            : ($this->existingPrivateKey ?: null);

        $publicKey = $this->sshKeyMode === 'generate'
            ? ($this->generatedKeyPair['public'] ?? '')
            : '';

        if ($this->isReprovision && $this->clusterId) {
            // Re-provisioning: update existing records
            $cluster = RedisCluster::findOrFail($this->clusterId);
            $cluster->update([
                'status' => 'pending',
                'auth_password_encrypted' => $this->authPassword,
                'sentinel_password_encrypted' => $this->sentinelPassword ?: $this->authPassword,
                'quorum' => $this->quorum,
            ]);

            $node = $cluster->nodes()->first();

            // Update server SSH details
            $serverData = [
                'host' => $this->masterHost,
                'ssh_port' => $this->masterSshPort,
                'ssh_user' => $this->masterSshUser,
            ];
            if (! empty($privateKey)) {
                $serverData['ssh_private_key_encrypted'] = $privateKey;
                $serverData['ssh_public_key'] = $publicKey;
            }
            $node->server->update($serverData);

            // Update node
            $node->update([
                'role' => 'pending',
                'status' => 'unknown',
                'redis_port' => $this->masterRedisPort,
                'sentinel_port' => $this->masterSentinelPort,
            ]);
        } else {
            // New cluster: create records
            $cluster = RedisCluster::create([
                'name' => $this->clusterName,
                'auth_password_encrypted' => $this->authPassword,
                'sentinel_password_encrypted' => $this->sentinelPassword ?: $this->authPassword,
                'quorum' => $this->quorum,
                'status' => 'pending',
            ]);
            $this->clusterId = $cluster->id;

            if ($this->serverMode === 'existing' && $this->selectedServerId) {
                // Reuse existing server
                $server = Server::findOrFail($this->selectedServerId);
            } else {
                // Create new server
                $server = Server::create([
                    'name' => $this->masterNodeName ?: "server-{$this->masterHost}",
                    'host' => $this->masterHost,
                    'ssh_port' => $this->masterSshPort,
                    'ssh_user' => $this->masterSshUser,
                    'ssh_private_key_encrypted' => $privateKey,
                    'ssh_public_key' => $publicKey,
                ]);
            }

            $node = RedisNode::create([
                'server_id' => $server->id,
                'redis_cluster_id' => $cluster->id,
                'name' => $this->masterNodeName ?: "node-1-{$this->masterHost}",
                'redis_port' => $this->masterRedisPort,
                'sentinel_port' => $this->masterSentinelPort,
                'role' => 'pending',
            ]);
        }

        // Initialise the progress cache
        Cache::put(ProvisionRedisClusterJob::progressKey($cluster->id), [
            'steps' => [
                ['message' => 'Provisioning job queued...', 'status' => 'running', 'time' => now()->format('H:i:s')],
            ],
            'status' => 'running',
        ], now()->addHours(2));

        // Dispatch to queue
        ProvisionRedisClusterJob::dispatch($cluster, $node);
    }

    /**
     * Poll for provisioning progress from the queued job.
     */
    public function pollProgress(): void
    {
        if (! $this->clusterId) {
            return;
        }

        $progress = Cache::get(ProvisionRedisClusterJob::progressKey($this->clusterId));

        if (! $progress) {
            return;
        }

        $this->provisionSteps = $progress['steps'];

        if ($progress['status'] === 'complete') {
            $this->provisioningComplete = true;
            $this->provisioning = false;
        } elseif ($progress['status'] === 'failed') {
            $this->provisioning = false;
        }
    }

    protected function addProvisionStep(string $message, string $status = 'running'): void
    {
        $this->provisionSteps[] = [
            'message' => $message,
            'status' => $status,
            'time' => now()->format('H:i:s'),
        ];
        $this->currentAction = $message;
    }

    protected function updateLastProvisionStep(string $message, string $status): void
    {
        if (count($this->provisionSteps) > 0) {
            $lastIndex = count($this->provisionSteps) - 1;
            $this->provisionSteps[$lastIndex]['message'] = $message;
            $this->provisionSteps[$lastIndex]['status'] = $status;
        }
        $this->currentAction = $message;
    }

    public function render()
    {
        // Exclude servers already used as Redis nodes (in any cluster)
        $usedServerIds = RedisNode::pluck('server_id');

        $servers = Server::whereNotNull('ssh_private_key_encrypted')
            ->whereNotIn('id', $usedServerIds)
            ->orderBy('name')
            ->get();

        return view('livewire.redis-setup-wizard', [
            'availableServers' => $servers,
        ])->layout('layouts.app', ['title' => __('Create Redis Cluster')]);
    }
}
