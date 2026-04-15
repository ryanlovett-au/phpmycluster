<?php

use App\Jobs\RefreshDbStatusJob;
use App\Models\Cluster;
use App\Models\Node;
use App\Services\MysqlShellService;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

it('updates cluster and node statuses from cluster status response', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $secondaryNode = Node::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.2',
        'mysql_port' => 3306,
    ]);

    $fakeStatusData = [
        'clusterName' => 'test-cluster',
        'defaultReplicaSet' => [
            'status' => 'OK',
            'topology' => [
                '10.0.0.1:3306' => [
                    'status' => 'ONLINE',
                    'memberRole' => 'PRIMARY',
                    'mode' => 'R/W',
                ],
                '10.0.0.2:3306' => [
                    'status' => 'ONLINE',
                    'memberRole' => 'SECONDARY',
                    'mode' => 'R/O',
                ],
            ],
        ],
    ];

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->withArgs(function (Node $node, string $password) use ($primaryNode) {
            return $node->id === $primaryNode->id;
        })
        ->andReturn([
            'success' => true,
            'data' => $fakeStatusData,
            'raw_output' => json_encode($fakeStatusData),
            'exit_code' => 0,
        ]);

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);

    // Cluster should be updated
    $cluster->refresh();
    expect($cluster->status->value)->toBe('online')
        ->and($cluster->last_status_json)->not->toBeNull()
        ->and($cluster->last_checked_at)->not->toBeNull();

    // Primary node should be online with primary role
    $primaryNode->refresh();
    expect($primaryNode->status->value)->toBe('online')
        ->and($primaryNode->role->value)->toBe('primary');

    // Secondary node should be online with secondary role
    $secondaryNode->refresh();
    expect($secondaryNode->status->value)->toBe('online')
        ->and($secondaryNode->role->value)->toBe('secondary');

    // Cache should be set
    expect(Cache::has("cluster_status_{$cluster->id}"))->toBeTrue();
});

it('marks nodes as error when member status is not ONLINE', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $secondaryNode = Node::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.2',
        'mysql_port' => 3306,
    ]);

    $fakeStatusData = [
        'clusterName' => 'test-cluster',
        'defaultReplicaSet' => [
            'status' => 'OK_PARTIAL',
            'topology' => [
                '10.0.0.1:3306' => [
                    'status' => 'ONLINE',
                    'memberRole' => 'PRIMARY',
                ],
                '10.0.0.2:3306' => [
                    'status' => 'UNREACHABLE',
                    'memberRole' => 'SECONDARY',
                ],
            ],
        ],
    ];

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => $fakeStatusData,
            'raw_output' => json_encode($fakeStatusData),
            'exit_code' => 0,
        ]);

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);

    $cluster->refresh();
    expect($cluster->status->value)->toBe('degraded');

    $secondaryNode->refresh();
    expect($secondaryNode->status->value)->toBe('error');
});

it('marks recovering nodes correctly', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $fakeStatusData = [
        'clusterName' => 'test-cluster',
        'defaultReplicaSet' => [
            'status' => 'OK_NO_TOLERANCE',
            'topology' => [
                '10.0.0.1:3306' => [
                    'status' => 'RECOVERING',
                    'memberRole' => 'SECONDARY',
                ],
            ],
        ],
    ];

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => $fakeStatusData,
            'raw_output' => json_encode($fakeStatusData),
            'exit_code' => 0,
        ]);

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);

    $primaryNode->refresh();
    expect($primaryNode->status->value)->toBe('recovering');
});

it('tries secondary nodes when primary fails', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $secondaryNode = Node::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.2',
        'mysql_port' => 3306,
    ]);

    $fakeStatusData = [
        'clusterName' => 'test-cluster',
        'defaultReplicaSet' => [
            'status' => 'OK',
            'topology' => [
                '10.0.0.1:3306' => [
                    'status' => 'ONLINE',
                    'memberRole' => 'PRIMARY',
                ],
                '10.0.0.2:3306' => [
                    'status' => 'ONLINE',
                    'memberRole' => 'SECONDARY',
                ],
            ],
        ],
    ];

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);

    // First call (primary) fails
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->withArgs(function (Node $node) use ($primaryNode) {
            return $node->id === $primaryNode->id;
        })
        ->andReturn([
            'success' => false,
            'data' => ['error' => 'Connection refused'],
            'raw_output' => 'Connection refused',
            'exit_code' => 1,
        ]);

    // Second call (secondary) succeeds
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->withArgs(function (Node $node) use ($secondaryNode) {
            return $node->id === $secondaryNode->id;
        })
        ->andReturn([
            'success' => true,
            'data' => $fakeStatusData,
            'raw_output' => json_encode($fakeStatusData),
            'exit_code' => 0,
        ]);

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);

    $cluster->refresh();
    expect($cluster->status->value)->toBe('online');
});

it('returns early when batch is cancelled', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
    ]);

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldNotReceive('getClusterStatus');

    // Create a partial mock of the job so we can mock batch()
    $jobMock = Mockery::mock(RefreshDbStatusJob::class, [$cluster])->makePartial();

    $fakeBatch = Mockery::mock(Batch::class);
    $fakeBatch->shouldReceive('cancelled')->andReturn(true);

    $jobMock->shouldReceive('batch')->andReturn($fakeBatch);

    $jobMock->handle($mysqlShellMock);
});

it('sets cluster status to degraded for NO_QUORUM', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $fakeStatusData = [
        'clusterName' => 'test-cluster',
        'defaultReplicaSet' => [
            'status' => 'NO_QUORUM',
            'topology' => [
                '10.0.0.1:3306' => [
                    'status' => 'ONLINE',
                    'memberRole' => 'PRIMARY',
                ],
            ],
        ],
    ];

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => $fakeStatusData,
            'raw_output' => json_encode($fakeStatusData),
            'exit_code' => 0,
        ]);

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);

    $cluster->refresh();
    expect($cluster->status->value)->toBe('degraded');
});

it('sets cluster status to offline for OFFLINE replica set status', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $fakeStatusData = [
        'clusterName' => 'test-cluster',
        'defaultReplicaSet' => [
            'status' => 'OFFLINE',
            'topology' => [
                '10.0.0.1:3306' => [
                    'status' => 'OFFLINE',
                    'memberRole' => 'PRIMARY',
                ],
            ],
        ],
    ];

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => $fakeStatusData,
            'raw_output' => json_encode($fakeStatusData),
            'exit_code' => 0,
        ]);

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);

    $cluster->refresh();
    expect($cluster->status->value)->toBe('offline');
});

it('logs warning when all nodes fail and captures last error from exception', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->andThrow(new RuntimeException('Connection timed out'));

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message) use ($cluster) {
            return str_contains($message, 'all nodes failed')
                && str_contains($message, (string) $cluster->id)
                && str_contains($message, 'Connection timed out');
        });

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);
});

it('captures last error from failed result data', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    $primaryNode = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldReceive('getClusterStatus')
        ->once()
        ->andReturn([
            'success' => true,
            'data' => ['error' => 'Cluster does not exist'],
            'raw_output' => 'Cluster does not exist',
            'exit_code' => 0,
        ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message) {
            return str_contains($message, 'Cluster does not exist');
        });

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);
});

it('skips execution when cluster has no db nodes', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_password_encrypted' => 'testpassword',
    ]);

    // Only create an access node, no db nodes
    Node::factory()->access()->create([
        'cluster_id' => $cluster->id,
    ]);

    $mysqlShellMock = Mockery::mock(MysqlShellService::class);
    $mysqlShellMock->shouldNotReceive('getClusterStatus');

    $job = new RefreshDbStatusJob($cluster);
    $job->handle($mysqlShellMock);
});
