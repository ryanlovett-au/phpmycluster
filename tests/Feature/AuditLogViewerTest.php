<?php

use App\Livewire\AuditLogViewer;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Node;
use Livewire\Livewire;

it('allows an approved user to view the audit logs page', function () {
    $user = createApprovedUser();

    $this->actingAs($user)
        ->get(route('audit-logs'))
        ->assertOk();
});

it('shows recent audit log entries', function () {
    $user = createApprovedUser();
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    AuditLog::factory()->create([
        'cluster_id' => $cluster->id,
        'node_id' => $node->id,
        'action' => 'provision.install_mysql',
        'status' => 'success',
    ]);

    Livewire::actingAs($user)
        ->test(AuditLogViewer::class)
        ->assertSee('provision.install_mysql');
});
