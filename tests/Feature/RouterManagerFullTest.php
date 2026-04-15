<?php

use App\Jobs\SetupRouterJob;
use App\Livewire\RouterManager;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Models\Server;
use App\Services\MysqlProvisionService;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// ─── mount() and render ─────────────────────────────────────────────────────

it('renders with cluster data', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create(['name' => 'my-cluster']);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSet('cluster.id', $cluster->id)
        ->assertSee('MySQL Router')
        ->assertSee('my-cluster');
});

it('shows router nodes', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.50']);
    $router = MysqlNode::factory()->access()->create([
        'server_id' => $server->id,
        'cluster_id' => $cluster->id,
        'name' => 'router-node-1',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSee('router-node-1')
        ->assertSee('10.0.0.50');
});

it('renders without errors when no router nodes exist', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    // No access nodes created — just DB nodes
    MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSee('MySQL Router')
        ->assertOk();
});

// ─── Default properties ─────────────────────────────────────────────────────

it('has correct default property values', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSet('showAddRouter', false)
        ->assertSet('routerHost', '')
        ->assertSet('routerName', '')
        ->assertSet('routerSshPort', 22)
        ->assertSet('routerSshUser', 'root')
        ->assertSet('routerSshKeyMode', 'generate')
        ->assertSet('routerAllowFrom', 'any')
        ->assertSet('settingUpRouter', false)
        ->assertSet('setupComplete', false)
        ->assertSet('renamingNodeId', null)
        ->assertSet('actionMessage', '')
        ->assertSet('actionStatus', '');
});

// ─── generateRouterKey() ───────────────────────────────────────────────────

it('generates a router SSH key pair', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    $keyPair = ['private' => 'test-priv', 'public' => 'ssh-ed25519 AAAA test'];
    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('generateKeyPair')->once()->andReturn($keyPair);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('generateRouterKey')
        ->assertSet('routerKeyPair', $keyPair);
});

// ─── setupRouter() ──────────────────────────────────────────────────────────

it('validates routerHost is required', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('routerHost', '')
        ->call('setupRouter')
        ->assertHasErrors(['routerHost' => 'required']);
});

it('creates a router node and dispatches the setup job', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('routerHost', '10.0.0.20')
        ->set('routerSshKeyMode', 'existing')
        ->set('routerPrivateKey', 'my-router-key')
        ->call('setupRouter')
        ->assertSet('settingUpRouter', true)
        ->assertSet('showAddRouter', false);

    Queue::assertPushed(SetupRouterJob::class);

    $routerNode = MysqlNode::whereHas('server', fn ($q) => $q->where('host', '10.0.0.20'))->first();
    expect($routerNode)->not->toBeNull();
    expect($routerNode->role->value)->toBe('access');
    expect($routerNode->name)->toBe('router-10.0.0.20');
});

it('uses custom router name when provided', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('routerHost', '10.0.0.20')
        ->set('routerName', 'my-custom-router')
        ->set('routerSshKeyMode', 'existing')
        ->set('routerPrivateKey', 'my-router-key')
        ->call('setupRouter')
        ->assertSet('settingUpRouter', true);

    $routerNode = MysqlNode::whereHas('server', fn ($q) => $q->where('host', '10.0.0.20'))->first();
    expect($routerNode->name)->toBe('my-custom-router');
});

// ─── pollSetup() ────────────────────────────────────────────────────────────

it('does nothing when no settingUpNodeId is set', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('pollSetup')
        ->assertSet('settingUpRouter', false);
});

it('updates steps from cache during router setup', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    $steps = [
        ['message' => 'Installing MySQL Router...', 'status' => 'running', 'time' => '12:00:00'],
    ];
    Cache::put(SetupRouterJob::progressKey($node->id), [
        'steps' => $steps,
        'status' => 'running',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', $node->id)
        ->set('settingUpRouter', true)
        ->call('pollSetup')
        ->assertSet('setupSteps', $steps)
        ->assertSet('settingUpRouter', true);
});

it('marks setup complete when cache says complete', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    Cache::put(SetupRouterJob::progressKey($node->id), [
        'steps' => [['message' => 'Router setup done', 'status' => 'success', 'time' => '12:01:00']],
        'status' => 'complete',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', $node->id)
        ->set('settingUpRouter', true)
        ->call('pollSetup')
        ->assertSet('setupComplete', true)
        ->assertSet('settingUpRouter', false);
});

it('stops setup on failure', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    Cache::put(SetupRouterJob::progressKey($node->id), [
        'steps' => [['message' => 'Error occurred', 'status' => 'error', 'time' => '12:01:00']],
        'status' => 'failed',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', $node->id)
        ->set('settingUpRouter', true)
        ->call('pollSetup')
        ->assertSet('settingUpRouter', false)
        ->assertSet('setupComplete', false);
});

// ─── retrySetup() ──────────────────────────────────────────────────────────

it('retries setting up a failed router', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'error',
        'name' => 'failed-router',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('retrySetup', $node->id)
        ->assertSet('settingUpRouter', true)
        ->assertSet('settingUpNodeId', $node->id);

    Queue::assertPushed(SetupRouterJob::class);
    expect($node->fresh()->status->value)->toBe('unknown');
});

// ─── dismissSetupProgress() ────────────────────────────────────────────────

it('dismisses setup progress panel', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('setupSteps', [['message' => 'test', 'status' => 'success', 'time' => '00:00']])
        ->set('setupComplete', true)
        ->set('settingUpNodeId', 999)
        ->call('dismissSetupProgress')
        ->assertSet('setupSteps', [])
        ->assertSet('setupComplete', false)
        ->assertSet('settingUpNodeId', null);
});

// ─── deleteRouter() ─────────────────────────────────────────────────────────

it('deletes an offline router node', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->offline()->create([
        'cluster_id' => $cluster->id,
        'name' => 'dead-router',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('deleteRouter', $router->id)
        ->assertSet('actionStatus', 'success');

    expect(MysqlNode::find($router->id))->toBeNull();
});

it('refuses to delete an online router', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'active-router',
        'status' => 'online',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('deleteRouter', $router->id)
        ->assertSet('actionStatus', 'error');

    expect(MysqlNode::find($router->id))->not->toBeNull();
});

// ─── checkRouterStatus() ───────────────────────────────────────────────────

it('checks router status and marks online', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'unknown',
    ]);

    $mock = Mockery::mock(MysqlProvisionService::class);
    $mock->shouldReceive('getRouterStatus')
        ->andReturn(['running' => true]);
    app()->instance(MysqlProvisionService::class, $mock);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('checkRouterStatus', $router->id)
        ->assertSet('actionStatus', 'success');

    expect($router->fresh()->status->value)->toBe('online');
});

it('checks router status and marks offline', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'online',
    ]);

    $mock = Mockery::mock(MysqlProvisionService::class);
    $mock->shouldReceive('getRouterStatus')
        ->andReturn(['running' => false]);
    app()->instance(MysqlProvisionService::class, $mock);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('checkRouterStatus', $router->id)
        ->assertSet('actionStatus', 'error');

    expect($router->fresh()->status->value)->toBe('offline');
});

// ─── startRename / saveRename / cancelRename ────────────────────────────────

it('starts renaming a router node', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'old-router-name',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('startRename', $router->id)
        ->assertSet('renamingNodeId', $router->id)
        ->assertSet('renameNodeValue', 'old-router-name');
});

it('saves a renamed router node', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'old-router-name',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('startRename', $router->id)
        ->set('renameNodeValue', 'new-router-name')
        ->call('saveRename')
        ->assertSet('renamingNodeId', null)
        ->assertSet('renameNodeValue', '');

    expect($router->fresh()->name)->toBe('new-router-name');
});

it('cancels renaming a router node', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('startRename', $router->id)
        ->assertSet('renamingNodeId', $router->id)
        ->call('cancelRename')
        ->assertSet('renamingNodeId', null)
        ->assertSet('renameNodeValue', '');
});

it('does nothing when saving rename without a renaming node', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('saveRename')
        ->assertSet('renamingNodeId', null);
});

it('validates rename value is required', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('startRename', $router->id)
        ->set('renameNodeValue', '')
        ->call('saveRename')
        ->assertHasErrors(['renameNodeValue' => 'required']);
});

// ─── Multiple router nodes ──────────────────────────────────────────────────

// ─── setupRouter() with generated key ─────────────────────────────────────

it('sets up a router with generated key pair', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('routerHost', '10.0.0.30')
        ->set('routerSshKeyMode', 'generate')
        ->set('routerKeyPair', ['private' => 'gen-router-priv', 'public' => 'gen-router-pub'])
        ->call('setupRouter')
        ->assertSet('settingUpRouter', true)
        ->assertSet('showAddRouter', false);

    Queue::assertPushed(SetupRouterJob::class);

    $routerNode = MysqlNode::whereHas('server', fn ($q) => $q->where('host', '10.0.0.30'))->first();
    expect($routerNode)->not->toBeNull();
    expect($routerNode->server->ssh_public_key)->toBe('gen-router-pub');
});

// Note: The catch block in setupRouter() (line 109-110) is defensive error handling
// that wraps MysqlNode::create + dispatch. It's excluded via @codeCoverageIgnore in source
// since triggering it requires mocking the Eloquent create method or job dispatch.

// ─── pollSetup() — no cache ──────────────────────────────────────────────

it('returns early from pollSetup when settingUpNodeId is set but no cache', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    // Set settingUpNodeId but don't populate cache
    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', $node->id)
        ->set('settingUpRouter', true)
        ->call('pollSetup')
        ->assertSet('settingUpRouter', true) // stays true since no progress found
        ->assertSet('setupSteps', []);
});

// ─── Multiple router nodes ──────────────────────────────────────────────────

it('displays multiple router nodes', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id, 'name' => 'router-a']);
    MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id, 'name' => 'router-b']);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSee('router-a')
        ->assertSee('router-b');
});
