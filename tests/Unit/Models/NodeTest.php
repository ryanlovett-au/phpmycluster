<?php

use App\Enums\NodeRole;
use App\Enums\NodeStatus;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses HasFactory trait', function () {
    expect(in_array(HasFactory::class, class_uses_recursive(Node::class)))->toBeTrue();
});

it('belongs to a cluster', function () {
    $node = Node::factory()->create();

    expect($node->cluster())->toBeInstanceOf(BelongsTo::class)
        ->and($node->cluster)->toBeInstanceOf(Cluster::class);
});

it('has auditLogs relationship', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    AuditLog::factory()->count(2)->create(['cluster_id' => $cluster->id, 'node_id' => $node->id]);

    expect($node->auditLogs())->toBeInstanceOf(HasMany::class)
        ->and($node->auditLogs)->toHaveCount(2);
});

it('returns true from isDbNode() for primary role', function () {
    $node = Node::factory()->primary()->make();

    expect($node->isDbNode())->toBeTrue();
});

it('returns true from isDbNode() for secondary role', function () {
    $node = Node::factory()->secondary()->make();

    expect($node->isDbNode())->toBeTrue();
});

it('returns true from isDbNode() for pending role', function () {
    $node = Node::factory()->make(['role' => NodeRole::Pending]);

    expect($node->isDbNode())->toBeTrue();
});

it('returns false from isDbNode() for access role', function () {
    $node = Node::factory()->access()->make();

    expect($node->isDbNode())->toBeFalse();
});

it('returns true from isAccessNode() for access role', function () {
    $node = Node::factory()->access()->make();

    expect($node->isAccessNode())->toBeTrue();
});

it('returns false from isAccessNode() for primary role', function () {
    $node = Node::factory()->primary()->make();

    expect($node->isAccessNode())->toBeFalse();
});

it('returns correct mysqlsh URI with default user', function () {
    $cluster = Cluster::factory()->make(['cluster_admin_user' => 'clusteradmin']);
    $node = Node::factory()->make([
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);
    $node->setRelation('cluster', $cluster);

    expect($node->getMysqlshUri())->toBe('clusteradmin@10.0.0.1:3306');
});

it('returns correct mysqlsh URI with custom user', function () {
    $node = Node::factory()->make([
        'host' => '10.0.0.5',
        'mysql_port' => 3307,
    ]);

    expect($node->getMysqlshUri('root'))->toBe('root@10.0.0.5:3307');
});

it('casts role to NodeRole enum', function () {
    $node = Node::factory()->primary()->create();

    expect($node->role)->toBe(NodeRole::Primary);
});

it('casts status to NodeStatus enum', function () {
    $node = Node::factory()->primary()->create();

    expect($node->status)->toBe(NodeStatus::Online);
});

it('casts ssh_private_key_encrypted as encrypted', function () {
    $node = Node::factory()->create(['ssh_private_key_encrypted' => 'my-private-key']);

    $rawValue = DB::table('nodes')->where('id', $node->id)->value('ssh_private_key_encrypted');

    expect($rawValue)->not->toBe('my-private-key')
        ->and($node->ssh_private_key_encrypted)->toBe('my-private-key');
});

it('casts mysql_root_password_encrypted as encrypted', function () {
    $node = Node::factory()->create(['mysql_root_password_encrypted' => 'root-secret']);

    $rawValue = DB::table('nodes')->where('id', $node->id)->value('mysql_root_password_encrypted');

    expect($rawValue)->not->toBe('root-secret')
        ->and($node->mysql_root_password_encrypted)->toBe('root-secret');
});

it('casts last_health_json to array', function () {
    $health = ['status' => 'ok', 'lag' => 0];
    $node = Node::factory()->create(['last_health_json' => $health]);

    $node->refresh();

    expect($node->last_health_json)->toBeArray()
        ->and($node->last_health_json['status'])->toBe('ok');
});

it('casts boolean fields correctly', function () {
    $node = Node::factory()->primary()->create();

    expect($node->mysql_installed)->toBeBool()
        ->and($node->mysql_shell_installed)->toBeBool()
        ->and($node->mysql_router_installed)->toBeBool()
        ->and($node->mysql_configured)->toBeBool();
});

it('hides ssh_private_key_encrypted from serialisation', function () {
    $node = Node::factory()->primary()->create([
        'ssh_private_key_encrypted' => 'secret-key-data',
    ]);

    $array = $node->toArray();

    expect($array)->not->toHaveKey('ssh_private_key_encrypted');
});

it('uses explicit fillable instead of guarded', function () {
    $node = new Node;

    expect($node->getFillable())->not->toBeEmpty()
        ->and($node->getGuarded())->toBe(['*']);
});
