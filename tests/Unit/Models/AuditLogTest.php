<?php

use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

it('uses HasFactory trait', function () {
    expect(in_array(HasFactory::class, class_uses_recursive(AuditLog::class)))->toBeTrue();
});

it('belongs to a cluster', function () {
    $log = AuditLog::factory()->create();

    expect($log->cluster())->toBeInstanceOf(BelongsTo::class)
        ->and($log->cluster)->toBeInstanceOf(Cluster::class);
});

it('belongs to a node', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    $log = AuditLog::factory()->forNode($node)->create();

    expect($log->node())->toBeInstanceOf(BelongsTo::class)
        ->and($log->node)->toBeInstanceOf(Node::class);
});

it('casts duration_ms to integer', function () {
    $log = AuditLog::factory()->create(['duration_ms' => '1234']);

    expect($log->duration_ms)->toBeInt()
        ->and($log->duration_ms)->toBe(1234);
});
