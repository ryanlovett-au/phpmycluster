<?php

namespace App\Livewire;

use App\Jobs\SetupRouterJob;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Models\Server;
use App\Services\MysqlProvisionService;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class RouterManager extends Component
{
    public MysqlCluster $cluster;

    // Add router node form
    public bool $showAddRouter = false;

    public string $routerHost = '';

    public string $routerName = '';

    public int $routerSshPort = 22;

    public string $routerSshUser = 'root';

    public string $routerSshKeyMode = 'generate';

    public string $routerPrivateKey = '';

    public ?array $routerKeyPair = null;

    public string $routerAllowFrom = 'any';

    // Setup progress
    public bool $settingUpRouter = false;

    public bool $setupComplete = false;

    public ?int $settingUpNodeId = null;

    public array $setupSteps = [];

    // Rename
    public ?int $renamingNodeId = null;

    public string $renameNodeValue = '';

    // Action feedback
    public string $actionMessage = '';

    public string $actionStatus = '';

    public function mount(MysqlCluster $cluster)
    {
        $this->cluster = $cluster;
    }

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
            $this->setupComplete = false;
            $this->settingUpNodeId = $node->id;
            $this->setupSteps = [
                ['message' => "Starting router setup for {$node->name}...", 'status' => 'running', 'time' => now()->format('H:i:s')],
            ];
            $this->showAddRouter = false;

        } catch (\Throwable $e) { // @codeCoverageIgnore
            $this->setMessage("Error creating router node: {$e->getMessage()}", 'error'); // @codeCoverageIgnore
        }
    }

    /**
     * Poll the setup job progress from the cache.
     */
    public function pollSetup(): void
    {
        if (! $this->settingUpNodeId) {
            return;
        }

        $progress = Cache::get(SetupRouterJob::progressKey($this->settingUpNodeId));

        if (! $progress) {
            return;
        }

        $this->setupSteps = $progress['steps'];

        if ($progress['status'] === 'complete') {
            $this->setupComplete = true;
            $this->settingUpRouter = false;
            $this->cluster->refresh();
            $this->resetForm();
        } elseif ($progress['status'] === 'failed') {
            $this->settingUpRouter = false;
        }
    }

    /**
     * Retry setting up a failed router node.
     */
    public function retrySetup(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);

        Cache::forget(SetupRouterJob::progressKey($node->id));
        $node->update(['status' => 'unknown']);

        SetupRouterJob::dispatch($this->cluster, $node, $this->routerAllowFrom);

        $this->settingUpRouter = true;
        $this->setupComplete = false;
        $this->settingUpNodeId = $node->id;
        $this->setupSteps = [
            ['message' => "Retrying router setup for {$node->name}...", 'status' => 'running', 'time' => now()->format('H:i:s')],
        ];
    }

    /**
     * Dismiss the setup progress panel.
     */
    public function dismissSetupProgress(): void
    {
        $this->setupSteps = [];
        $this->setupComplete = false;
        $this->settingUpNodeId = null;
        $this->cluster->refresh();
    }

    /**
     * Delete a failed/pending router node.
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
     * Check router status on a node.
     */
    public function checkRouterStatus(int $nodeId)
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

    /**
     * Start renaming a router node.
     */
    public function startRename(int $nodeId): void
    {
        $node = MysqlNode::findOrFail($nodeId);
        $this->renamingNodeId = $nodeId;
        $this->renameNodeValue = $node->name;
    }

    /**
     * Save the new router node name.
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
        ])->layout('layouts.app', ['title' => __('MySQL Router').' - '.$this->cluster->name]);
    }
}
