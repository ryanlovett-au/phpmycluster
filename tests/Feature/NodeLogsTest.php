<?php

use App\Livewire\NodeLogs;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\LogStreamService;
use Livewire\Livewire;

it('allows an approved user to view node logs', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $this->actingAs($user)
        ->get(route('node.logs', $node))
        ->assertOk();
});

it('mounts with the given node', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'log-test-node',
    ]);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->assertSet('node.id', $node->id)
        ->assertSee('log-test-node');
});

it('handles unknown log type gracefully', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->set('logType', 'nonexistent')
        ->call('fetchLogs')
        ->assertSet('logContent', 'Unknown log type.')
        ->assertSet('loading', false);
});

it('can switch log type via setLogType', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    // Mock the LogStreamService to avoid SSH calls
    $this->mock(LogStreamService::class, function ($mock) {
        $mock->shouldReceive('getSlowLog')->once()->andReturn(['output' => 'slow query log output']);
    });

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->call('setLogType', 'slow')
        ->assertSet('logType', 'slow')
        ->assertSet('logContent', 'slow query log output');
});

it('shows error output when log fetch returns error key', function () {
    $user = createApprovedUser();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $this->mock(LogStreamService::class, function ($mock) {
        $mock->shouldReceive('getErrorLog')->once()->andReturn(['error' => 'Connection refused']);
    });

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->call('fetchLogs')
        ->assertSet('logContent', 'Connection refused');
});
