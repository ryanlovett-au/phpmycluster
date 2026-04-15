<?php

use App\Jobs\RefreshUserListJob;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\MysqlShellService;
use Illuminate\Support\Facades\Cache;

it('caches users and databases from listUsers and listDatabases', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $fakeUsers = [
        ['user' => 'app_user', 'host' => '%', 'has_password' => true, 'database' => '*.*', 'privileges' => 'ALL PRIVILEGES'],
        ['user' => 'readonly', 'host' => '10.0.0.%', 'has_password' => true, 'database' => 'myapp.*', 'privileges' => 'SELECT'],
    ];

    $fakeDatabases = ['myapp', 'analytics', 'staging'];

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);

    $mysqlShellMock->shouldReceive('listUsers')
        ->once()
        ->withArgs(function (MysqlNode $node, string $password) use ($primaryNode) {
            return $node->id === $primaryNode->id;
        })
        ->andReturn([
            'success' => true,
            'data' => $fakeUsers,
            'raw_output' => json_encode($fakeUsers),
            'exit_code' => 0,
        ]);

    $mysqlShellMock->shouldReceive('listDatabases')
        ->once()
        ->withArgs(function (MysqlNode $node, string $password) use ($primaryNode) {
            return $node->id === $primaryNode->id;
        })
        ->andReturn([
            'success' => true,
            'data' => $fakeDatabases,
            'raw_output' => json_encode($fakeDatabases),
            'exit_code' => 0,
        ]);

    $job = new RefreshUserListJob($cluster);
    $job->handle($mysqlShellMock);

    expect(Cache::get("cluster_users_{$cluster->id}"))->toBe($fakeUsers)
        ->and(Cache::get("cluster_databases_{$cluster->id}"))->toBe($fakeDatabases);
});

it('skips when cluster has no primary node', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    // Only secondary, no primary
    MysqlNode::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldNotReceive('listUsers');
    $mysqlShellMock->shouldNotReceive('listDatabases');

    $job = new RefreshUserListJob($cluster);
    $job->handle($mysqlShellMock);
});

it('does not cache when listUsers returns error', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);

    $mysqlShellMock->shouldReceive('listUsers')
        ->once()
        ->andReturn([
            'success' => false,
            'data' => ['error' => 'Access denied'],
            'raw_output' => 'Access denied',
            'exit_code' => 1,
        ]);

    $mysqlShellMock->shouldReceive('listDatabases')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => ['myapp'],
            'raw_output' => '["myapp"]',
            'exit_code' => 0,
        ]);

    $job = new RefreshUserListJob($cluster);
    $job->handle($mysqlShellMock);

    expect(Cache::has("cluster_users_{$cluster->id}"))->toBeFalse()
        ->and(Cache::get("cluster_databases_{$cluster->id}"))->toBe(['myapp']);
});

it('handles exceptions gracefully without crashing', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('listUsers')
        ->once()
        ->andThrow(new RuntimeException('SSH connection failed'));

    // listDatabases should not be called because exception happens first
    $mysqlShellMock->shouldReceive('listDatabases')->never();

    $job = new RefreshUserListJob($cluster);

    // Should not throw
    $job->handle($mysqlShellMock);

    expect(Cache::has("cluster_users_{$cluster->id}"))->toBeFalse();
});
