<?php

use App\Livewire\ClusterManager;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

it('allows an approved user to view the cluster manager page', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    $this->actingAs($user)
        ->get(route('mysql.manage', $cluster))
        ->assertOk();
});

it('redirects an unapproved user away from the cluster manager', function () {
    $user = createPendingUser();
    $cluster = MysqlCluster::factory()->online()->create();

    $this->actingAs($user)
        ->get(route('mysql.manage', $cluster))
        ->assertRedirect(route('approval.pending'));
});

it('renders the cluster name', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create(['name' => 'my-production-cluster']);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->assertSee('my-production-cluster');
});

it('shows DB nodes (non-access role)', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $primary = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-primary-node',
    ]);
    $secondary = MysqlNode::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-secondary-node',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->assertSee('db-primary-node')
        ->assertSee('db-secondary-node');
});

it('shows router nodes (access role)', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $router = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-node-1',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->assertSee('router-node-1');
});

it('dispatches a bus batch when refreshStatus is called', function () {
    Bus::fake();

    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->call('refreshStatus');

    Bus::assertBatched(fn ($batch) => str_contains($batch->name, $cluster->name));
});

it('sets renamingNodeId when startRename is called', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'original-name',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->call('startRename', $node->id)
        ->assertSet('renamingNodeId', $node->id)
        ->assertSet('renameNodeValue', 'original-name');
});

it('updates the node name when saveRename is called', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'old-name',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->call('startRename', $node->id)
        ->set('renameNodeValue', 'new-name')
        ->call('saveRename');

    expect($node->fresh()->name)->toBe('new-name');
});

it('clears renamingNodeId when cancelRename is called', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->call('startRename', $node->id)
        ->assertSet('renamingNodeId', $node->id)
        ->call('cancelRename')
        ->assertSet('renamingNodeId', null);
});

it('deletes the cluster and redirects to dashboard', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster])
        ->call('deleteCluster')
        ->assertRedirect(route('dashboard'));

    expect(MysqlCluster::find($cluster->id))->toBeNull();
});

it('splits nodes into DB nodes and router nodes in render', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();

    $primary = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-node',
    ]);
    $router = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-node',
    ]);

    $component = Livewire::actingAs($user)
        ->test(ClusterManager::class, ['cluster' => $cluster]);

    $nodes = $component->viewData('nodes');
    $routerNodes = $component->viewData('routerNodes');

    expect($nodes->pluck('name')->toArray())->toContain('db-node')
        ->and($nodes->pluck('name')->toArray())->not->toContain('router-node')
        ->and($routerNodes->pluck('name')->toArray())->toContain('router-node')
        ->and($routerNodes->pluck('name')->toArray())->not->toContain('db-node');
});
