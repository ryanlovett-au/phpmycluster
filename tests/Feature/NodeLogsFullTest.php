<?php

use App\Livewire\NodeLogs;
use App\Models\Cluster;
use App\Models\Node;
use App\Services\LogStreamService;
use Livewire\Livewire;

// ─── mount() and render ─────────────────────────────────────────────────────

it('renders with node data', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-primary-node',
        'host' => '10.0.0.1',
    ]);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->assertSet('node.id', $node->id)
        ->assertSee('db-primary-node')
        ->assertSee('10.0.0.1')
        ->assertSee('Logs');
});

it('renders log type selector buttons', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->assertSee('Error Log')
        ->assertSee('Slow Query')
        ->assertSee('General')
        ->assertSee('Systemd')
        ->assertSee('Router');
});

it('defaults to error log type', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->assertSet('logType', 'error')
        ->assertSet('lines', 100)
        ->assertSet('autoRefresh', false)
        ->assertSet('logContent', '');
});

// ─── fetchLogs() ────────────────────────────────────────────────────────────

it('fetches error logs from the log stream service', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getErrorLog')
        ->once()
        ->with(Mockery::on(fn ($n) => $n->id === $node->id), 100)
        ->andReturn(['output' => '2024-01-01 ERROR: Test error message']);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->call('fetchLogs')
        ->assertSet('logContent', '2024-01-01 ERROR: Test error message')
        ->assertSet('loading', false);
});

it('fetches slow query logs', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getSlowLog')
        ->once()
        ->andReturn(['output' => 'Slow query log content']);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->set('logType', 'slow')
        ->call('fetchLogs')
        ->assertSet('logContent', 'Slow query log content');
});

it('fetches general logs', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getGeneralLog')
        ->once()
        ->andReturn(['output' => 'General log content']);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->set('logType', 'general')
        ->call('fetchLogs')
        ->assertSet('logContent', 'General log content');
});

it('fetches systemd logs', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getSystemdLog')
        ->once()
        ->andReturn(['output' => 'Systemd journal output']);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->set('logType', 'systemd')
        ->call('fetchLogs')
        ->assertSet('logContent', 'Systemd journal output');
});

it('fetches router logs', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->access()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getRouterLog')
        ->once()
        ->andReturn(['output' => 'Router log entries']);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->set('logType', 'router')
        ->call('fetchLogs')
        ->assertSet('logContent', 'Router log entries');
});

it('handles error responses from log service', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getErrorLog')
        ->once()
        ->andReturn(['error' => 'Connection refused']);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->call('fetchLogs')
        ->assertSet('logContent', 'Connection refused');
});

it('shows "No output." when log service returns no data', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getErrorLog')
        ->once()
        ->andReturn([]);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->call('fetchLogs')
        ->assertSet('logContent', 'No output.');
});

// ─── setLogType() ───────────────────────────────────────────────────────────

it('changes log type and fetches new logs', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $mock = Mockery::mock(LogStreamService::class);
    $mock->shouldReceive('getSlowLog')
        ->once()
        ->andReturn(['output' => 'Slow log data']);
    app()->instance(LogStreamService::class, $mock);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->call('setLogType', 'slow')
        ->assertSet('logType', 'slow')
        ->assertSet('logContent', 'Slow log data');
});

// ─── Empty state ────────────────────────────────────────────────────────────

it('shows placeholder text when no logs have been fetched', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->assertSee('Click "Fetch Logs" to load log data from the node.');
});

// ─── Node role display ──────────────────────────────────────────────────────

it('displays the node role', function () {
    $user = createAdmin();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'my-primary-node',
    ]);

    Livewire::actingAs($user)
        ->test(NodeLogs::class, ['node' => $node])
        ->assertSee('Primary');
});
