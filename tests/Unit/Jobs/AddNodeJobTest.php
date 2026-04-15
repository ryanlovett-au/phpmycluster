<?php

use App\Enums\NodeRole;
use App\Enums\NodeStatus;
use App\Jobs\AddNodeJob;
use App\Models\Cluster;
use App\Models\Node;
use App\Services\FirewallService;
use App\Services\MysqlShellService;
use App\Services\NodeProvisionService;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

it('has a timeout of 1800 seconds', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    $job = new AddNodeJob($cluster, $node);

    expect($job->timeout)->toBe(1800);
});

it('has tries set to 1', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    $job = new AddNodeJob($cluster, $node);

    expect($job->tries)->toBe(1);
});

it('returns the correct progress key format', function () {
    $key = AddNodeJob::progressKey(42);

    expect($key)->toBe('add_node_progress_42');
});

it('accepts Cluster and Node in the constructor', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    $job = new AddNodeJob($cluster, $node);

    expect($job->cluster)->toBeInstanceOf(Cluster::class);
    expect($job->cluster->id)->toBe($cluster->id);
    expect($job->node)->toBeInstanceOf(Node::class);
    expect($job->node->id)->toBe($node->id);
});

it('adds a node to an existing cluster successfully', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $primary = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $newNode = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(AddNodeJob::class, [$cluster, $newNode])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([
        'mysql_installed' => true,
        'shell_installed' => true,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();
    $firewallService->shouldReceive('allowNewNodeOnCluster')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('addInstance')->once()->andReturn([
        'success' => true,
        'data' => ['status' => 'ok'],
        'raw_output' => '',
    ]);
    $mysqlShell->shouldReceive('getClusterStatus')->once()->andReturn([
        'success' => true,
        'data' => ['clusterName' => 'test-cluster', 'status' => 'OK'],
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $newNode->refresh();
    expect($newNode->role)->toBe(NodeRole::Secondary);
    expect($newNode->status)->toBe(NodeStatus::Online);

    $progress = Cache::get(AddNodeJob::progressKey($newNode->id));
    expect($progress['status'])->toBe('complete');
});

it('sets node to error when addInstance fails', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $primary = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $newNode = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(AddNodeJob::class, [$cluster, $newNode])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([
        'mysql_installed' => true,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();
    $firewallService->shouldReceive('allowNewNodeOnCluster')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('addInstance')->once()->andReturn([
        'success' => false,
        'data' => ['error' => 'Instance already in group'],
        'raw_output' => 'Instance already in group',
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $newNode->refresh();
    expect($newNode->status)->toBe(NodeStatus::Error);

    $progress = Cache::get(AddNodeJob::progressKey($newNode->id));
    expect($progress['status'])->toBe('failed');
});

it('sets node to error when addInstance returns data error', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $primary = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $newNode = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(AddNodeJob::class, [$cluster, $newNode])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();
    $firewallService->shouldReceive('allowNewNodeOnCluster')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('addInstance')->once()->andReturn([
        'success' => true,
        'data' => ['error' => 'Some error occurred'],
        'raw_output' => '',
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $newNode->refresh();
    expect($newNode->status)->toBe(NodeStatus::Error);
});

it('throws when no primary node found in cluster', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    // No primary node created — only the new node
    $newNode = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(AddNodeJob::class, [$cluster, $newNode])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();
    $firewallService->shouldReceive('allowNewNodeOnCluster')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $newNode->refresh();
    expect($newNode->status)->toBe(NodeStatus::Error);

    $progress = Cache::get(AddNodeJob::progressKey($newNode->id));
    expect($progress['status'])->toBe('failed');
    expect(collect($progress['steps'])->last()['message'])->toContain('No primary node found');
});

it('sets node to error when provisionNode throws exception', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $newNode = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(AddNodeJob::class, [$cluster, $newNode])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andThrow(
        new RuntimeException('SSH connection refused')
    );

    $firewallService = Mockery::mock(FirewallService::class);
    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $newNode->refresh();
    expect($newNode->status)->toBe(NodeStatus::Error);

    $progress = Cache::get(AddNodeJob::progressKey($newNode->id));
    expect($progress['status'])->toBe('failed');
});

it('updates cluster status after successful node addition', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $primary = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $newNode = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(AddNodeJob::class, [$cluster, $newNode])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();
    $firewallService->shouldReceive('allowNewNodeOnCluster')->once();

    $statusData = ['clusterName' => 'test-cluster', 'status' => 'OK', 'topology' => []];

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('addInstance')->once()->andReturn([
        'success' => true,
        'data' => [],
        'raw_output' => '',
    ]);
    $mysqlShell->shouldReceive('getClusterStatus')->once()->andReturn([
        'success' => true,
        'data' => $statusData,
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $cluster->refresh();
    expect($cluster->last_status_json)->toBe($statusData);
    expect($cluster->last_checked_at)->not->toBeNull();
});

it('skips cluster status update when getClusterStatus fails', function () {
    $cluster = Cluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'last_status_json' => null,
    ]);
    $primary = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $newNode = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(AddNodeJob::class, [$cluster, $newNode])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();
    $firewallService->shouldReceive('allowNewNodeOnCluster')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('addInstance')->once()->andReturn([
        'success' => true,
        'data' => [],
        'raw_output' => '',
    ]);
    $mysqlShell->shouldReceive('getClusterStatus')->once()->andReturn([
        'success' => false,
        'data' => null,
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $cluster->refresh();
    // Status JSON should not have been updated since getClusterStatus failed
    expect($cluster->last_status_json)->toBeNull();

    $progress = Cache::get(AddNodeJob::progressKey($newNode->id));
    expect($progress['status'])->toBe('complete');
});

it('returns getRootPassword as cluster admin password', function () {
    $cluster = Cluster::factory()->create([
        'cluster_admin_password_encrypted' => 'my-cluster-admin-pass',
    ]);
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    $job = new AddNodeJob($cluster, $node);

    $reflection = new ReflectionMethod($job, 'getRootPassword');
    $result = $reflection->invoke($job, $cluster, $node);

    expect($result)->toBe('my-cluster-admin-pass');
});
