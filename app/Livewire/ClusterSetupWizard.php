<?php

namespace App\Livewire;

use App\Jobs\ProvisionClusterJob;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Models\Server;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Validate;
use Livewire\Component;

class ClusterSetupWizard extends Component
{
    public int $step = 1;

    // Step 1: Cluster details
    #[Validate('required|string|max:255|unique:clusters,name')]
    public string $clusterName = '';

    public string $communicationStack = 'MYSQL';

    public string $clusterAdminUser = 'clusteradmin';

    #[Validate('required|string|min:12')]
    public string $clusterAdminPassword = '';

    // Step 2: Seed node details
    #[Validate('required|string')]
    public string $seedHost = '';

    public int $seedSshPort = 22;

    public string $seedSshUser = 'root';

    public int $seedMysqlPort = 3306;

    public string $seedName = '';

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

    // MySQL root password for initial setup
    #[Validate('required|string')]
    public string $mysqlRootPassword = '';

    // Re-provisioning mode
    public bool $isReprovision = false;

    public bool $sshKeyMissing = false;

    public bool $sshKeyAuthFailed = false;

    // Results
    public ?int $clusterId = null;

    public ?array $clusterStatus = null;

    public function mount(?MysqlCluster $cluster = null): void
    {
        if ($cluster && $cluster->exists) {
            $this->isReprovision = true;
            $this->clusterId = $cluster->id;
            $this->clusterName = $cluster->name;
            $this->communicationStack = $cluster->communication_stack;
            $this->clusterAdminUser = $cluster->cluster_admin_user;
            $this->clusterAdminPassword = $cluster->cluster_admin_password_encrypted;

            $node = $cluster->nodes()->first();
            if ($node) {
                $this->seedName = $node->name;
                $this->seedHost = $node->server->host;
                $this->seedSshPort = $node->server->ssh_port;
                $this->seedSshUser = $node->server->ssh_user;
                $this->seedMysqlPort = $node->mysql_port;
                $this->mysqlRootPassword = $node->mysql_root_password_encrypted ?? '';

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
                ? "required|string|max:255|unique:clusters,name,{$this->clusterId}"
                : 'required|string|max:255|unique:clusters,name';

            $this->validate([
                'clusterName' => $uniqueRule,
                'clusterAdminPassword' => 'required|string|min:12',
            ]);
        }

        if ($this->step === 2) {
            $this->validate([
                'seedHost' => 'required|string',
            ]);
        }

        $this->step++;
    }

    public function previousStep()
    {
        $this->step = max(1, $this->step - 1);
    }

    /**
     * Generate an SSH keypair for the seed node.
     */
    public function generateSshKey()
    {
        $sshService = app(SshService::class);
        $this->generatedKeyPair = $sshService->generateKeyPair();
    }

    /**
     * Test SSH connectivity to the seed node.
     */
    public function testSshConnection()
    {
        $this->provisionSteps = [];
        $this->parseHostField();
        $this->addProvisionStep("Testing SSH connection to {$this->seedSshUser}@{$this->seedHost}:{$this->seedSshPort}...");

        $privateKey = $this->sshKeyMode === 'generate'
            ? $this->generatedKeyPair['private']
            : $this->existingPrivateKey;

        try {
            $sshService = app(SshService::class);
            $result = $sshService->testConnectionDirect(
                $this->seedHost,
                $this->seedSshPort,
                $this->seedSshUser,
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
        if (str_contains($this->seedHost, '@')) {
            [$user, $host] = explode('@', $this->seedHost, 2);
            $this->seedSshUser = $user;
            $this->seedHost = $host;
        }
    }

    /**
     * Dispatch the provisioning job to the queue.
     */
    public function provision()
    {
        if (! $this->isReprovision) {
            $this->validate([
                'mysqlRootPassword' => 'required|string',
            ]);
        }

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
            $cluster = MysqlCluster::findOrFail($this->clusterId);
            $cluster->update(['status' => 'pending']);

            $node = $cluster->nodes()->first();

            // Use the stored root password if not provided
            if (empty($this->mysqlRootPassword)) {
                $this->mysqlRootPassword = $node->mysql_root_password_encrypted ?? '';
            }

            // Update server SSH details
            $serverData = [
                'host' => $this->seedHost,
                'ssh_port' => $this->seedSshPort,
                'ssh_user' => $this->seedSshUser,
            ];
            if (! empty($privateKey)) {
                $serverData['ssh_private_key_encrypted'] = $privateKey;
                $serverData['ssh_public_key'] = $publicKey;
            }
            $node->server->update($serverData);

            // Update node
            $nodeData = ['role' => 'pending', 'status' => 'unknown'];
            if (! empty($this->mysqlRootPassword)) {
                $nodeData['mysql_root_password_encrypted'] = $this->mysqlRootPassword;
            }
            $node->update($nodeData);
        } else {
            // New cluster: create records
            $cluster = MysqlCluster::create([
                'name' => $this->clusterName,
                'communication_stack' => $this->communicationStack,
                'cluster_admin_user' => $this->clusterAdminUser,
                'cluster_admin_password_encrypted' => $this->clusterAdminPassword,
                'status' => 'pending',
            ]);
            $this->clusterId = $cluster->id;

            $server = Server::create([
                'name' => $this->seedName ?: "server-{$this->seedHost}",
                'host' => $this->seedHost,
                'ssh_port' => $this->seedSshPort,
                'ssh_user' => $this->seedSshUser,
                'ssh_private_key_encrypted' => $privateKey,
                'ssh_public_key' => $publicKey,
            ]);

            $node = MysqlNode::create([
                'server_id' => $server->id,
                'cluster_id' => $cluster->id,
                'name' => $this->seedName ?: "node-1-{$this->seedHost}",
                'mysql_port' => $this->seedMysqlPort,
                'mysql_root_password_encrypted' => $this->mysqlRootPassword,
                'role' => 'pending',
                'mysql_server_id' => 1,
            ]);
        }

        // Initialise the progress cache
        Cache::put(ProvisionClusterJob::progressKey($cluster->id), [
            'steps' => [
                ['message' => 'Provisioning job queued...', 'status' => 'running', 'time' => now()->format('H:i:s')],
            ],
            'status' => 'running',
        ], now()->addHours(2));

        // Dispatch to queue
        ProvisionClusterJob::dispatch($cluster, $node, $this->mysqlRootPassword, $this->clusterAdminPassword);
    }

    /**
     * Poll for provisioning progress from the queued job.
     */
    public function pollProgress(): void
    {
        if (! $this->clusterId) {
            return;
        }

        $progress = Cache::get(ProvisionClusterJob::progressKey($this->clusterId));

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
        return view('livewire.cluster-setup-wizard')
            ->layout('layouts.app', ['title' => __('Create Cluster')]);
    }
}
