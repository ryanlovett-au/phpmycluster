<?php

use App\Enums\MysqlNodeRole;
use App\Enums\MysqlNodeStatus;
use App\Models\AuditLog;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses HasFactory trait', function () {
    expect(in_array(HasFactory::class, class_uses_recursive(MysqlNode::class)))->toBeTrue();
});

it('belongs to a cluster', function () {
    $node = MysqlNode::factory()->create();

    expect($node->cluster())->toBeInstanceOf(BelongsTo::class)
        ->and($node->cluster)->toBeInstanceOf(MysqlCluster::class);
});

it('has auditLogs relationship', function () {
    $cluster = MysqlCluster::factory()->create();
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);
    AuditLog::factory()->count(2)->create(['cluster_id' => $cluster->id, 'node_id' => $node->id]);

    expect($node->auditLogs())->toBeInstanceOf(HasMany::class)
        ->and($node->auditLogs)->toHaveCount(2);
});

it('returns true from isDbNode() for primary role', function () {
    $node = MysqlNode::factory()->primary()->make();

    expect($node->isDbNode())->toBeTrue();
});

it('returns true from isDbNode() for secondary role', function () {
    $node = MysqlNode::factory()->secondary()->make();

    expect($node->isDbNode())->toBeTrue();
});

it('returns true from isDbNode() for pending role', function () {
    $node = MysqlNode::factory()->make(['role' => MysqlNodeRole::Pending]);

    expect($node->isDbNode())->toBeTrue();
});

it('returns false from isDbNode() for access role', function () {
    $node = MysqlNode::factory()->access()->make();

    expect($node->isDbNode())->toBeFalse();
});

it('returns true from isAccessNode() for access role', function () {
    $node = MysqlNode::factory()->access()->make();

    expect($node->isAccessNode())->toBeTrue();
});

it('returns false from isAccessNode() for primary role', function () {
    $node = MysqlNode::factory()->primary()->make();

    expect($node->isAccessNode())->toBeFalse();
});

it('returns correct mysqlsh URI with default user', function () {
    $cluster = MysqlCluster::factory()->make(['cluster_admin_user' => 'clusteradmin']);
    $server = Server::factory()->make(['host' => '10.0.0.1']);
    $node = MysqlNode::factory()->make([
        'mysql_port' => 3306,
    ]);
    $node->setRelation('cluster', $cluster);
    $node->setRelation('server', $server);

    expect($node->getMysqlshUri())->toBe('clusteradmin@10.0.0.1:3306');
});

it('returns correct mysqlsh URI with custom user', function () {
    $server = Server::factory()->make(['host' => '10.0.0.5']);
    $node = MysqlNode::factory()->make([
        'mysql_port' => 3307,
    ]);
    $node->setRelation('server', $server);

    expect($node->getMysqlshUri('root'))->toBe('root@10.0.0.5:3307');
});

it('casts role to MysqlNodeRole enum', function () {
    $node = MysqlNode::factory()->primary()->create();

    expect($node->role)->toBe(MysqlNodeRole::Primary);
});

it('casts status to MysqlNodeStatus enum', function () {
    $node = MysqlNode::factory()->primary()->create();

    expect($node->status)->toBe(MysqlNodeStatus::Online);
});

it('casts ssh_private_key_encrypted as encrypted on server', function () {
    $server = Server::factory()->create(['ssh_private_key_encrypted' => 'my-private-key']);

    $rawValue = DB::table('servers')->where('id', $server->id)->value('ssh_private_key_encrypted');

    expect($rawValue)->not->toBe('my-private-key')
        ->and($server->ssh_private_key_encrypted)->toBe('my-private-key');
});

it('casts mysql_root_password_encrypted as encrypted', function () {
    $node = MysqlNode::factory()->create(['mysql_root_password_encrypted' => 'root-secret']);

    $rawValue = DB::table('nodes')->where('id', $node->id)->value('mysql_root_password_encrypted');

    expect($rawValue)->not->toBe('root-secret')
        ->and($node->mysql_root_password_encrypted)->toBe('root-secret');
});

it('casts last_health_json to array', function () {
    $health = ['status' => 'ok', 'lag' => 0];
    $node = MysqlNode::factory()->create(['last_health_json' => $health]);

    $node->refresh();

    expect($node->last_health_json)->toBeArray()
        ->and($node->last_health_json['status'])->toBe('ok');
});

it('casts boolean fields correctly', function () {
    $node = MysqlNode::factory()->primary()->create();

    expect($node->mysql_installed)->toBeBool()
        ->and($node->mysql_shell_installed)->toBeBool()
        ->and($node->mysql_router_installed)->toBeBool()
        ->and($node->mysql_configured)->toBeBool();
});

it('hides ssh_private_key_encrypted from serialisation on server', function () {
    $server = Server::factory()->create([
        'ssh_private_key_encrypted' => 'secret-key-data',
    ]);

    $array = $server->toArray();

    expect($array)->not->toHaveKey('ssh_private_key_encrypted');
});

it('uses explicit fillable instead of guarded', function () {
    $node = new MysqlNode;

    expect($node->getFillable())->not->toBeEmpty()
        ->and($node->getGuarded())->toBe(['*']);
});
