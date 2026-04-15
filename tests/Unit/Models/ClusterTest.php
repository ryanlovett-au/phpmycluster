<?php

use App\Enums\ClusterStatus;
use App\Enums\NodeRole;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses HasFactory trait', function () {
    expect(in_array(HasFactory::class, class_uses_recursive(Cluster::class)))->toBeTrue();
});

it('has nodes relationship', function () {
    $cluster = Cluster::factory()->create();
    Node::factory()->count(3)->create(['cluster_id' => $cluster->id]);

    expect($cluster->nodes())->toBeInstanceOf(HasMany::class)
        ->and($cluster->nodes)->toHaveCount(3);
});

it('has dbNodes relationship filtering to primary, secondary, and pending roles', function () {
    $cluster = Cluster::factory()->create();
    Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    Node::factory()->secondary()->create(['cluster_id' => $cluster->id]);
    Node::factory()->create(['cluster_id' => $cluster->id, 'role' => NodeRole::Pending]);
    Node::factory()->access()->create(['cluster_id' => $cluster->id]);

    expect($cluster->dbNodes())->toBeInstanceOf(HasMany::class)
        ->and($cluster->dbNodes)->toHaveCount(3);
});

it('has accessNodes relationship filtering to access role', function () {
    $cluster = Cluster::factory()->create();
    Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    Node::factory()->access()->create(['cluster_id' => $cluster->id]);
    Node::factory()->access()->create(['cluster_id' => $cluster->id]);

    expect($cluster->accessNodes())->toBeInstanceOf(HasMany::class)
        ->and($cluster->accessNodes)->toHaveCount(2);
});

it('has auditLogs relationship', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    AuditLog::factory()->count(2)->create(['cluster_id' => $cluster->id, 'node_id' => $node->id]);

    expect($cluster->auditLogs())->toBeInstanceOf(HasMany::class)
        ->and($cluster->auditLogs)->toHaveCount(2);
});

it('returns the primary node via primaryNode()', function () {
    $cluster = Cluster::factory()->create();
    $primary = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    Node::factory()->secondary()->create(['cluster_id' => $cluster->id]);

    $result = $cluster->primaryNode();

    expect($result)->toBeInstanceOf(Node::class)
        ->and($result->id)->toBe($primary->id);
});

it('returns null from primaryNode() when no primary exists', function () {
    $cluster = Cluster::factory()->create();
    Node::factory()->secondary()->create(['cluster_id' => $cluster->id]);

    expect($cluster->primaryNode())->toBeNull();
});

it('returns primary first from reachableDbNode()', function () {
    $cluster = Cluster::factory()->create();
    $primary = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    Node::factory()->secondary()->create(['cluster_id' => $cluster->id]);

    $result = $cluster->reachableDbNode();

    expect($result->id)->toBe($primary->id);
});

it('falls back to online secondary from reachableDbNode() when no primary', function () {
    $cluster = Cluster::factory()->create();
    $secondary = Node::factory()->secondary()->create(['cluster_id' => $cluster->id]);

    $result = $cluster->reachableDbNode();

    expect($result->id)->toBe($secondary->id);
});

it('falls back to any DB node from reachableDbNode() as last resort', function () {
    $cluster = Cluster::factory()->create();
    $offlineSecondary = Node::factory()->secondary()->offline()->create(['cluster_id' => $cluster->id]);

    $result = $cluster->reachableDbNode();

    expect($result->id)->toBe($offlineSecondary->id);
});

it('returns null from reachableDbNode() when no DB nodes exist', function () {
    $cluster = Cluster::factory()->create();
    Node::factory()->access()->create(['cluster_id' => $cluster->id]);

    expect($cluster->reachableDbNode())->toBeNull();
});

it('builds comma-separated IP allowlist from DB nodes', function () {
    $cluster = Cluster::factory()->create();
    Node::factory()->primary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.1']);
    Node::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.2']);
    Node::factory()->access()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.3']);

    $result = $cluster->buildIpAllowlist();

    expect($result)->toBe('10.0.0.1,10.0.0.2');
});

it('returns empty string from buildIpAllowlist() when no DB nodes exist', function () {
    $cluster = Cluster::factory()->create();
    Node::factory()->access()->create(['cluster_id' => $cluster->id]);

    expect($cluster->buildIpAllowlist())->toBe('');
});

it('casts status to ClusterStatus enum', function () {
    $cluster = Cluster::factory()->online()->create();

    expect($cluster->status)->toBe(ClusterStatus::Online);
});

it('casts last_status_json to array', function () {
    $data = ['topology' => 'single-primary', 'members' => 3];
    $cluster = Cluster::factory()->create(['last_status_json' => $data]);

    $cluster->refresh();

    expect($cluster->last_status_json)->toBeArray()
        ->and($cluster->last_status_json['topology'])->toBe('single-primary');
});

it('casts cluster_admin_password_encrypted as encrypted', function () {
    $cluster = Cluster::factory()->create(['cluster_admin_password_encrypted' => 'my-secret-password']);

    // The value stored in DB should not be plaintext
    $rawValue = DB::table('clusters')->where('id', $cluster->id)->value('cluster_admin_password_encrypted');

    expect($rawValue)->not->toBe('my-secret-password')
        ->and($cluster->cluster_admin_password_encrypted)->toBe('my-secret-password');
});

it('uses explicit fillable instead of guarded', function () {
    $cluster = new Cluster;

    expect($cluster->getFillable())->not->toBeEmpty()
        ->and($cluster->getGuarded())->toBe(['*']);
});
