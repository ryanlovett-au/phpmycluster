<?php

use App\Enums\RedisClusterStatus;
use App\Models\AuditLog;
use App\Models\RedisCluster;
use App\Models\RedisNode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses HasFactory trait', function () {
    expect(in_array(HasFactory::class, class_uses_recursive(RedisCluster::class)))->toBeTrue();
});

it('has nodes relationship', function () {
    $cluster = RedisCluster::factory()->create();
    RedisNode::factory()->count(3)->create(['redis_cluster_id' => $cluster->id]);

    expect($cluster->nodes())->toBeInstanceOf(HasMany::class)
        ->and($cluster->nodes)->toHaveCount(3);
});

it('has replicaNodes relationship filtering to replica role', function () {
    $cluster = RedisCluster::factory()->create();
    RedisNode::factory()->master()->create(['redis_cluster_id' => $cluster->id]);
    RedisNode::factory()->replica()->create(['redis_cluster_id' => $cluster->id]);
    RedisNode::factory()->replica()->create(['redis_cluster_id' => $cluster->id]);

    expect($cluster->replicaNodes())->toBeInstanceOf(HasMany::class)
        ->and($cluster->replicaNodes)->toHaveCount(2);
});

it('has auditLogs relationship', function () {
    $cluster = RedisCluster::factory()->create();
    $node = RedisNode::factory()->create(['redis_cluster_id' => $cluster->id]);
    AuditLog::create(['redis_cluster_id' => $cluster->id, 'redis_node_id' => $node->id, 'action' => 'test', 'status' => 'success']);
    AuditLog::create(['redis_cluster_id' => $cluster->id, 'redis_node_id' => $node->id, 'action' => 'test', 'status' => 'success']);

    expect($cluster->auditLogs())->toBeInstanceOf(HasMany::class)
        ->and($cluster->auditLogs)->toHaveCount(2);
});

it('returns the master node via masterNode()', function () {
    $cluster = RedisCluster::factory()->create();
    $master = RedisNode::factory()->master()->create(['redis_cluster_id' => $cluster->id]);
    RedisNode::factory()->replica()->create(['redis_cluster_id' => $cluster->id]);

    $result = $cluster->masterNode();

    expect($result)->toBeInstanceOf(RedisNode::class)
        ->and($result->id)->toBe($master->id);
});

it('returns null from masterNode() when no master exists', function () {
    $cluster = RedisCluster::factory()->create();
    RedisNode::factory()->replica()->create(['redis_cluster_id' => $cluster->id]);

    expect($cluster->masterNode())->toBeNull();
});

it('returns master first from reachableNode()', function () {
    $cluster = RedisCluster::factory()->create();
    $master = RedisNode::factory()->master()->create(['redis_cluster_id' => $cluster->id]);
    RedisNode::factory()->replica()->create(['redis_cluster_id' => $cluster->id]);

    $result = $cluster->reachableNode();

    expect($result->id)->toBe($master->id);
});

it('falls back to online replica from reachableNode() when no master', function () {
    $cluster = RedisCluster::factory()->create();
    $replica = RedisNode::factory()->replica()->create(['redis_cluster_id' => $cluster->id]);

    $result = $cluster->reachableNode();

    expect($result->id)->toBe($replica->id);
});

it('falls back to any node from reachableNode() as last resort', function () {
    $cluster = RedisCluster::factory()->create();
    $offlineNode = RedisNode::factory()->replica()->offline()->create(['redis_cluster_id' => $cluster->id]);

    $result = $cluster->reachableNode();

    expect($result->id)->toBe($offlineNode->id);
});

it('returns null from reachableNode() when no nodes exist', function () {
    $cluster = RedisCluster::factory()->create();

    expect($cluster->reachableNode())->toBeNull();
});

it('casts status to RedisClusterStatus enum', function () {
    $cluster = RedisCluster::factory()->online()->create();

    expect($cluster->status)->toBe(RedisClusterStatus::Online);
});

it('casts last_status_json to array', function () {
    $data = ['master' => 'redis-node-1', 'replicas' => 2];
    $cluster = RedisCluster::factory()->create(['last_status_json' => $data]);

    $cluster->refresh();

    expect($cluster->last_status_json)->toBeArray()
        ->and($cluster->last_status_json['master'])->toBe('redis-node-1');
});

it('casts auth_password_encrypted as encrypted', function () {
    $cluster = RedisCluster::factory()->create(['auth_password_encrypted' => 'my-redis-password']);

    $rawValue = DB::table('redis_clusters')->where('id', $cluster->id)->value('auth_password_encrypted');

    expect($rawValue)->not->toBe('my-redis-password')
        ->and($cluster->auth_password_encrypted)->toBe('my-redis-password');
});

it('casts sentinel_password_encrypted as encrypted', function () {
    $cluster = RedisCluster::factory()->create(['sentinel_password_encrypted' => 'my-sentinel-password']);

    $rawValue = DB::table('redis_clusters')->where('id', $cluster->id)->value('sentinel_password_encrypted');

    expect($rawValue)->not->toBe('my-sentinel-password')
        ->and($cluster->sentinel_password_encrypted)->toBe('my-sentinel-password');
});

it('uses explicit fillable instead of guarded', function () {
    $cluster = new RedisCluster;

    expect($cluster->getFillable())->not->toBeEmpty()
        ->and($cluster->getGuarded())->toBe(['*']);
});
