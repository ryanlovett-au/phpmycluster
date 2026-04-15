<?php

use App\Enums\RedisNodeRole;
use App\Enums\RedisNodeStatus;
use App\Models\AuditLog;
use App\Models\RedisCluster;
use App\Models\RedisNode;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses HasFactory trait', function () {
    expect(in_array(HasFactory::class, class_uses_recursive(RedisNode::class)))->toBeTrue();
});

it('belongs to a cluster', function () {
    $node = RedisNode::factory()->create();

    expect($node->cluster())->toBeInstanceOf(BelongsTo::class)
        ->and($node->cluster)->toBeInstanceOf(RedisCluster::class);
});

it('belongs to a server', function () {
    $node = RedisNode::factory()->create();

    expect($node->server())->toBeInstanceOf(BelongsTo::class)
        ->and($node->server)->toBeInstanceOf(Server::class);
});

it('has auditLogs relationship', function () {
    $cluster = RedisCluster::factory()->create();
    $node = RedisNode::factory()->create(['redis_cluster_id' => $cluster->id]);
    AuditLog::create(['redis_cluster_id' => $cluster->id, 'redis_node_id' => $node->id, 'action' => 'test', 'status' => 'success']);
    AuditLog::create(['redis_cluster_id' => $cluster->id, 'redis_node_id' => $node->id, 'action' => 'test', 'status' => 'success']);

    expect($node->auditLogs())->toBeInstanceOf(HasMany::class)
        ->and($node->auditLogs)->toHaveCount(2);
});

it('returns true from isMaster() for master role', function () {
    $node = RedisNode::factory()->master()->make();

    expect($node->isMaster())->toBeTrue();
});

it('returns false from isMaster() for replica role', function () {
    $node = RedisNode::factory()->replica()->make();

    expect($node->isMaster())->toBeFalse();
});

it('returns true from isReplica() for replica role', function () {
    $node = RedisNode::factory()->replica()->make();

    expect($node->isReplica())->toBeTrue();
});

it('returns false from isReplica() for master role', function () {
    $node = RedisNode::factory()->master()->make();

    expect($node->isReplica())->toBeFalse();
});

it('returns correct redis-cli URI', function () {
    $server = Server::factory()->make(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->make([
        'redis_port' => 6379,
    ]);
    $node->setRelation('server', $server);

    expect($node->getRedisCliUri())->toBe('-h 10.0.0.1 -p 6379');
});

it('casts role to RedisNodeRole enum', function () {
    $node = RedisNode::factory()->master()->create();

    expect($node->role)->toBe(RedisNodeRole::Master);
});

it('casts status to RedisNodeStatus enum', function () {
    $node = RedisNode::factory()->master()->create();

    expect($node->status)->toBe(RedisNodeStatus::Online);
});

it('casts boolean fields correctly', function () {
    $node = RedisNode::factory()->master()->create();

    expect($node->redis_installed)->toBeBool()
        ->and($node->sentinel_installed)->toBeBool()
        ->and($node->redis_configured)->toBeBool();
});

it('casts last_health_json to array', function () {
    $health = ['status' => 'ok', 'connected_clients' => 5];
    $node = RedisNode::factory()->create(['last_health_json' => $health]);

    $node->refresh();

    expect($node->last_health_json)->toBeArray()
        ->and($node->last_health_json['status'])->toBe('ok');
});

it('implements SshConnectable getServer returns Server', function () {
    $node = RedisNode::factory()->create();

    expect($node->getServer())->toBeInstanceOf(Server::class);
});

it('implements SshConnectable getAuditContext returns correct keys', function () {
    $node = RedisNode::factory()->create();

    $context = $node->getAuditContext();

    expect($context)->toBeArray()
        ->and($context)->toHaveKey('redis_cluster_id')
        ->and($context['redis_cluster_id'])->toBe($node->redis_cluster_id)
        ->and($context)->toHaveKey('redis_node_id')
        ->and($context['redis_node_id'])->toBe($node->id);
});

it('uses explicit fillable instead of guarded', function () {
    $node = new RedisNode;

    expect($node->getFillable())->not->toBeEmpty()
        ->and($node->getGuarded())->toBe(['*']);
});
