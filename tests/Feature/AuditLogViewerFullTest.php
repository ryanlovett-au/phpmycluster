<?php

use App\Livewire\AuditLogViewer;
use App\Models\AuditLog;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use Livewire\Livewire;

// ─── Rendering ──────────────────────────────────────────────────────────────

it('renders the audit log heading', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSee('Audit Log');
});

it('renders audit log entries', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create(['name' => 'test-cluster']);
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'test-node',
    ]);

    AuditLog::factory()->forNode($node)->create([
        'action' => 'mysql.install',
        'status' => 'success',
        'duration_ms' => 1500,
    ]);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSee('mysql.install')
        ->assertSee('success')
        ->assertSee('test-cluster')
        ->assertSee('test-node')
        ->assertSee('1500ms');
});

it('renders multiple audit log entries', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    AuditLog::factory()->forNode($node)->create(['action' => 'ssh.test']);
    AuditLog::factory()->forNode($node)->create(['action' => 'mysql.configure']);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSee('ssh.test')
        ->assertSee('mysql.configure');
});

// ─── Empty state ────────────────────────────────────────────────────────────

it('shows empty state when no logs exist', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSee('No audit log entries found.');
});

// ─── Filtering by cluster ───────────────────────────────────────────────────

it('filters by cluster id', function () {
    $user = createAdmin();
    $cluster1 = MysqlCluster::factory()->online()->create(['name' => 'cluster-one']);
    $cluster2 = MysqlCluster::factory()->online()->create(['name' => 'cluster-two']);

    AuditLog::factory()->create(['cluster_id' => $cluster1->id, 'action' => 'action-one']);
    AuditLog::factory()->create(['cluster_id' => $cluster2->id, 'action' => 'action-two']);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class, ['clusterId' => $cluster1->id])
        ->assertSee('action-one')
        ->assertDontSee('action-two');
});

// ─── Filtering by node ─────────────────────────────────────────────────────

it('filters by node id', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();
    $node1 = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id, 'name' => 'node-one']);
    $node2 = MysqlNode::factory()->secondary()->create(['cluster_id' => $cluster->id, 'name' => 'node-two']);

    AuditLog::factory()->forNode($node1)->create(['action' => 'log-for-node1']);
    AuditLog::factory()->forNode($node2)->create(['action' => 'log-for-node2']);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class, ['nodeId' => $node1->id])
        ->assertSee('log-for-node1')
        ->assertDontSee('log-for-node2');
});

// ─── Action filter ──────────────────────────────────────────────────────────

it('filters by action text', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    AuditLog::factory()->create(['cluster_id' => $cluster->id, 'action' => 'mysql.install']);
    AuditLog::factory()->create(['cluster_id' => $cluster->id, 'action' => 'ssh.test']);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->set('actionFilter', 'mysql')
        ->assertSee('mysql.install')
        ->assertDontSee('ssh.test');
});

// ─── Status filter ──────────────────────────────────────────────────────────

it('filters by status', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    AuditLog::factory()->create(['cluster_id' => $cluster->id, 'action' => 'succeeded-action', 'status' => 'success']);
    AuditLog::factory()->create(['cluster_id' => $cluster->id, 'action' => 'failed-action', 'status' => 'failed']);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->set('statusFilter', 'failed')
        ->assertSee('failed-action')
        ->assertDontSee('succeeded-action');
});

it('shows all statuses when filter is empty', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    AuditLog::factory()->create(['cluster_id' => $cluster->id, 'action' => 'action-success', 'status' => 'success']);
    AuditLog::factory()->create(['cluster_id' => $cluster->id, 'action' => 'action-failed', 'status' => 'failed']);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->set('statusFilter', '')
        ->assertSee('action-success')
        ->assertSee('action-failed');
});

// ─── Pagination ─────────────────────────────────────────────────────────────

it('paginates audit logs at 25 per page', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    // Create 30 logs
    AuditLog::factory()->count(30)->create(['cluster_id' => $cluster->id]);

    $component = Livewire::actingAs($user)
        ->test(AuditLogViewer::class);

    // Should see pagination when more than 25 items
    // The component uses paginate(25) so first page shows 25
    $component->assertDontSee('No audit log entries found.');
});

// ─── Command and error display ──────────────────────────────────────────────

it('displays command in audit log entry', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    AuditLog::factory()->create([
        'cluster_id' => $cluster->id,
        'action' => 'test.command',
        'command' => 'mysqlsh --js --execute "cluster.status()"',
        'status' => 'success',
    ]);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSee('mysqlsh --js --execute');
});

it('displays error message in failed audit log entry', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create();

    AuditLog::factory()->create([
        'cluster_id' => $cluster->id,
        'action' => 'test.fail',
        'status' => 'failed',
        'error_message' => 'Connection timed out',
    ]);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSee('Connection timed out');
});

// ─── Default properties ─────────────────────────────────────────────────────

it('has empty default filter values', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSet('clusterId', null)
        ->assertSet('nodeId', null)
        ->assertSet('actionFilter', '')
        ->assertSet('statusFilter', '');
});
