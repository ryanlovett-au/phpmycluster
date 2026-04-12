<?php

namespace App\Livewire;

use App\Models\Cluster;
use App\Models\Node;
use App\Services\FirewallService;
use App\Services\NodeProvisionService;
use App\Services\SshService;
use Livewire\Component;

class RouterManager extends Component
{
    public Cluster $cluster;

    // Add router node form
    public bool $showAddRouter = false;

    public string $routerHost = '';

    public string $routerName = '';

    public int $routerSshPort = 22;

    public string $routerSshUser = 'root';

    public string $routerSshKeyMode = 'generate';

    public string $routerPrivateKey = '';

    public ?array $routerKeyPair = null;

    public string $routerAllowFrom = 'any'; // which IPs can connect to the router

    public string $actionMessage = '';

    public string $actionStatus = '';

    public function mount(Cluster $cluster)
    {
        $this->cluster = $cluster;
    }

    public function generateRouterKey()
    {
        $this->routerKeyPair = app(SshService::class)->generateKeyPair();
    }

    /**
     * Set up MySQL Router on a new access node.
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
            $node = Node::create([
                'cluster_id' => $this->cluster->id,
                'name' => $this->routerName ?: "router-{$this->routerHost}",
                'host' => $this->routerHost,
                'ssh_port' => $this->routerSshPort,
                'ssh_user' => $this->routerSshUser,
                'ssh_private_key_encrypted' => $privateKey,
                'ssh_public_key' => $publicKey,
                'role' => 'access',
            ]);

            $provisionService = app(NodeProvisionService::class);
            $firewallService = app(FirewallService::class);

            // Install MySQL Router
            $this->setMessage('Installing MySQL Router...', 'info');
            $installResult = $provisionService->installMysqlRouter($node);
            if (! $installResult['installed']) {
                throw new \RuntimeException('Failed to install MySQL Router.');
            }

            // Configure firewall
            $this->setMessage('Configuring firewall...', 'info');
            $firewallService->configureAccessNode($node, $this->cluster, $this->routerAllowFrom);

            // Also update DB node firewalls to allow this router
            foreach ($this->cluster->dbNodes as $dbNode) {
                app(SshService::class)->exec(
                    $dbNode,
                    "ufw allow from {$node->host} to any port {$dbNode->mysql_port} proto tcp comment 'MySQL from router {$node->name}'",
                    'firewall.rule',
                    sudo: true
                );
            }

            // Bootstrap the router
            $primary = $this->cluster->primaryNode();
            if (! $primary) {
                throw new \RuntimeException('No primary node found to bootstrap router against.');
            }

            $this->setMessage('Bootstrapping MySQL Router...', 'info');
            $bootstrapResult = $provisionService->bootstrapRouter(
                $node,
                $primary,
                $this->cluster->cluster_admin_password_encrypted
            );

            if ($bootstrapResult['success']) {
                $node->update(['status' => 'online', 'mysql_router_installed' => true]);
                $this->setMessage("Router {$node->name} set up and running.", 'success');
            } else {
                throw new \RuntimeException('Router bootstrap failed: '.$bootstrapResult['output']);
            }

            $this->resetForm();
            $this->cluster->refresh();

        } catch (\Throwable $e) {
            $this->setMessage("Error: {$e->getMessage()}", 'error');
        }
    }

    /**
     * Check router status on a node.
     */
    public function checkRouterStatus(int $nodeId)
    {
        $node = Node::findOrFail($nodeId);
        $result = app(NodeProvisionService::class)->getRouterStatus($node);

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

    protected function setMessage(string $message, string $status): void
    {
        $this->actionMessage = $message;
        $this->actionStatus = $status;
    }

    protected function resetForm(): void
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
        return view('livewire.router-manager', [
            'routerNodes' => $this->cluster->accessNodes()->get(),
        ]);
    }
}
