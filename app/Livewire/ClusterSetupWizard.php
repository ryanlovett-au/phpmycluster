<?php

namespace App\Livewire;

use App\Models\Cluster;
use App\Models\Node;
use App\Services\FirewallService;
use App\Services\MysqlShellService;
use App\Services\NodeProvisionService;
use App\Services\SshService;
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

    // Results
    public ?int $clusterId = null;

    public ?array $clusterStatus = null;

    public function nextStep()
    {
        if ($this->step === 1) {
            $this->validate([
                'clusterName' => 'required|string|max:255|unique:clusters,name',
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
        $this->addProvisionStep('Testing SSH connection...');

        $privateKey = $this->sshKeyMode === 'generate'
            ? $this->generatedKeyPair['private']
            : $this->existingPrivateKey;

        // Create a temporary node for testing
        $node = new Node([
            'host' => $this->seedHost,
            'ssh_port' => $this->seedSshPort,
            'ssh_user' => $this->seedSshUser,
            'ssh_private_key_encrypted' => $privateKey,
        ]);

        $sshService = app(SshService::class);
        $result = $sshService->testConnection($node);

        if ($result['success']) {
            $this->addProvisionStep("Connected! Hostname: {$result['hostname']}, OS: {$result['os']}", 'success');
        } else {
            $this->addProvisionStep("Connection failed: {$result['error']}", 'error');
        }

        return $result;
    }

    /**
     * Run the full provisioning process.
     */
    public function provision()
    {
        $this->validate([
            'mysqlRootPassword' => 'required|string',
        ]);

        $this->provisioning = true;
        $this->provisionSteps = [];

        $privateKey = $this->sshKeyMode === 'generate'
            ? $this->generatedKeyPair['private']
            : $this->existingPrivateKey;

        $publicKey = $this->sshKeyMode === 'generate'
            ? $this->generatedKeyPair['public']
            : '';

        try {
            // 1. Create the cluster record
            $this->addProvisionStep('Creating cluster record...');
            $cluster = Cluster::create([
                'name' => $this->clusterName,
                'communication_stack' => $this->communicationStack,
                'cluster_admin_user' => $this->clusterAdminUser,
                'cluster_admin_password_encrypted' => $this->clusterAdminPassword,
                'status' => 'pending',
            ]);
            $this->clusterId = $cluster->id;

            // 2. Create the seed node record
            $this->addProvisionStep('Registering seed node...');
            $node = Node::create([
                'cluster_id' => $cluster->id,
                'name' => $this->seedName ?: "node-1-{$this->seedHost}",
                'host' => $this->seedHost,
                'ssh_port' => $this->seedSshPort,
                'ssh_user' => $this->seedSshUser,
                'ssh_private_key_encrypted' => $privateKey,
                'ssh_public_key' => $publicKey,
                'mysql_port' => $this->seedMysqlPort,
                'role' => 'pending',
                'server_id' => 1,
            ]);

            $provisionService = app(NodeProvisionService::class);
            $firewallService = app(FirewallService::class);
            $mysqlShell = app(MysqlShellService::class);

            // 3. Detect OS
            $this->addProvisionStep('Detecting operating system...');
            $os = $provisionService->detectOs($node);
            $this->addProvisionStep('OS: '.($os['pretty_name'] ?? 'Unknown'), 'success');

            // 4. Install MySQL
            $this->addProvisionStep('Installing MySQL Server and MySQL Shell (this may take a few minutes)...');
            $installResult = $provisionService->installMysql($node);
            if ($installResult['mysql_installed']) {
                $this->addProvisionStep("MySQL installed: {$installResult['mysql_version']}", 'success');
            } else {
                throw new \RuntimeException('MySQL installation failed. Check audit logs for details.');
            }

            // 5. Write MySQL config
            $this->addProvisionStep('Writing InnoDB Cluster configuration...');
            $configResult = $provisionService->writeMysqlConfig($node);
            if (! $configResult['success']) {
                throw new \RuntimeException('Failed to write MySQL configuration.');
            }
            $this->addProvisionStep('MySQL configuration written.', 'success');

            // 6. Restart MySQL
            $this->addProvisionStep('Restarting MySQL...');
            $restartResult = $provisionService->restartMysql($node);
            if (! $restartResult['success']) {
                throw new \RuntimeException('Failed to restart MySQL.');
            }
            $this->addProvisionStep('MySQL restarted.', 'success');

            // 7. Configure instance for InnoDB Cluster
            $this->addProvisionStep('Configuring instance for InnoDB Cluster...');
            $configureResult = $mysqlShell->configureInstance(
                $node,
                $this->mysqlRootPassword,
                $this->clusterAdminUser,
                $this->clusterAdminPassword,
            );
            if (! $configureResult['success'] || isset($configureResult['data']['error'])) {
                throw new \RuntimeException('Failed to configure instance: '.($configureResult['data']['error'] ?? $configureResult['raw_output']));
            }
            $this->addProvisionStep('Instance configured.', 'success');

            // 8. Restart MySQL again after configuration
            $this->addProvisionStep('Restarting MySQL after configuration...');
            $provisionService->restartMysql($node);
            sleep(3); // Give MySQL time to fully start
            $this->addProvisionStep('MySQL restarted.', 'success');

            // 9. Configure firewall
            $this->addProvisionStep('Configuring UFW firewall...');
            $firewallService->configureDbNode($node, $cluster);
            $this->addProvisionStep('Firewall configured.', 'success');

            // 10. Create the cluster
            $this->addProvisionStep('Creating InnoDB Cluster...');
            $createResult = $mysqlShell->createCluster($node, $cluster, $this->clusterAdminPassword);
            if (! $createResult['success'] || isset($createResult['data']['error'])) {
                throw new \RuntimeException('Failed to create cluster: '.($createResult['data']['error'] ?? $createResult['raw_output']));
            }
            $this->addProvisionStep('InnoDB Cluster created!', 'success');

            // 11. Update records
            $node->update(['role' => 'primary', 'status' => 'online']);
            $cluster->update([
                'status' => 'online',
                'last_status_json' => $createResult['data'],
                'ip_allowlist' => $cluster->buildIpAllowlist(),
                'last_checked_at' => now(),
            ]);

            $this->clusterStatus = $createResult['data'];
            $this->provisioningComplete = true;
            $this->addProvisionStep('Cluster setup complete!', 'success');

        } catch (\Throwable $e) {
            $this->addProvisionStep("Error: {$e->getMessage()}", 'error');
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

    public function render()
    {
        return view('livewire.cluster-setup-wizard')
            ->layout('layouts.app');
    }
}
