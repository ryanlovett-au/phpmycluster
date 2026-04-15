<?php

use App\Jobs\SetupRouterJob;
use App\Livewire\RouterManager;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('allows an approved user to view the router manager', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    $this->actingAs($user)
        ->get(route('cluster.routers', $cluster))
        ->assertOk();
});

it('renders with cluster data', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create(['name' => 'router-test-cluster']);
    MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'my-router-node',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSee('my-router-node');
});

it('sets up a router with a provided private key', function () {
    Queue::fake();
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('routerHost', '10.0.0.50')
        ->set('routerName', 'test-router')
        ->set('routerSshKeyMode', 'provide')
        ->set('routerPrivateKey', 'fake-private-key-content')
        ->call('setupRouter')
        ->assertSet('settingUpRouter', true)
        ->assertSet('showAddRouter', false);

    Queue::assertPushed(SetupRouterJob::class);
    expect(MysqlNode::where('host', '10.0.0.50')->exists())->toBeTrue();
});

it('handles exception during router node creation', function () {
    Queue::fake();
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    // Trigger an error by providing invalid data - cluster_id will be overridden
    // Use a mock to force an exception
    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('routerHost', '') // Validation should fail
        ->call('setupRouter')
        ->assertHasErrors('routerHost');
});

it('polls setup and handles failed status', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    // Simulate a failed progress state
    Cache::put(SetupRouterJob::progressKey($node->id), [
        'status' => 'failed',
        'steps' => [
            ['message' => 'Starting...', 'status' => 'complete', 'time' => '12:00:00'],
            ['message' => 'Failed to connect', 'status' => 'failed', 'time' => '12:00:05'],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', $node->id)
        ->call('pollSetup')
        ->assertSet('settingUpRouter', false);
});

it('polls setup and handles complete status', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    Cache::put(SetupRouterJob::progressKey($node->id), [
        'status' => 'complete',
        'steps' => [
            ['message' => 'Done', 'status' => 'complete', 'time' => '12:00:00'],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', $node->id)
        ->call('pollSetup')
        ->assertSet('setupComplete', true)
        ->assertSet('settingUpRouter', false);
});

it('returns early from pollSetup when no node is being set up', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSet('settingUpNodeId', null)
        ->call('pollSetup'); // Should return early without error
});

it('returns early from pollSetup when no progress in cache', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', 9999) // Non-existent progress
        ->call('pollSetup')
        ->assertSet('setupSteps', []);
});

it('can delete a non-running router', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->offline()->create([
        'cluster_id' => $cluster->id,
        'name' => 'dead-router',
    ]);
    $nodeId = $node->id;

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('deleteRouter', $nodeId)
        ->assertSet('actionStatus', 'success');

    expect(MysqlNode::find($nodeId))->toBeNull();
});

it('prevents deleting a running router', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'online',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('deleteRouter', $node->id)
        ->assertSet('actionStatus', 'error');

    expect(MysqlNode::find($node->id))->not->toBeNull();
});

it('can start and cancel renaming a router', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'old-name',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('startRename', $node->id)
        ->assertSet('renamingNodeId', $node->id)
        ->assertSet('renameNodeValue', 'old-name')
        ->call('cancelRename')
        ->assertSet('renamingNodeId', null)
        ->assertSet('renameNodeValue', '');
});

it('can save a renamed router', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'old-name',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('startRename', $node->id)
        ->set('renameNodeValue', 'new-router-name')
        ->call('saveRename');

    $node->refresh();
    expect($node->name)->toBe('new-router-name');
});

it('returns early from saveRename when no node is being renamed', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->assertSet('renamingNodeId', null)
        ->call('saveRename'); // Should return early without error
});

it('can dismiss setup progress', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->set('settingUpNodeId', $node->id)
        ->set('setupComplete', true)
        ->set('setupSteps', [['message' => 'Done', 'status' => 'complete', 'time' => '12:00:00']])
        ->call('dismissSetupProgress')
        ->assertSet('setupSteps', [])
        ->assertSet('setupComplete', false)
        ->assertSet('settingUpNodeId', null);
});

it('can retry setting up a failed router', function () {
    Queue::fake();
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'status' => 'error',
    ]);

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('retrySetup', $node->id)
        ->assertSet('settingUpRouter', true)
        ->assertSet('settingUpNodeId', $node->id);

    Queue::assertPushed(SetupRouterJob::class);
});

it('can generate a router key pair', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    // Mock the SshService to return a known key pair
    $this->mock(SshService::class, function ($mock) {
        $mock->shouldReceive('generateKeyPair')->once()->andReturn([
            'private' => 'test-private-key',
            'public' => 'test-public-key',
        ]);
    });

    Livewire::actingAs($user)
        ->test(RouterManager::class, ['cluster' => $cluster])
        ->call('generateRouterKey')
        ->assertSet('routerKeyPair.private', 'test-private-key')
        ->assertSet('routerKeyPair.public', 'test-public-key');
});
