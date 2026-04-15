<?php

use App\Enums\ClusterStatus;
use App\Enums\NodeRole;
use App\Enums\NodeStatus;
use App\Jobs\ProvisionClusterJob;
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
    $job = new ProvisionClusterJob($cluster, $node, 'root-pass', 'admin-pass');

    expect($job->timeout)->toBe(1800);
});

it('has tries set to 1', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    $job = new ProvisionClusterJob($cluster, $node, 'root-pass', 'admin-pass');

    expect($job->tries)->toBe(1);
});

it('returns the correct progress key format', function () {
    $key = ProvisionClusterJob::progressKey(7);

    expect($key)->toBe('provision_progress_7');
});

it('provisions a new cluster successfully', function () {
    $cluster = Cluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(ProvisionClusterJob::class, [$cluster, $node, 'rootpass', 'adminpass'])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([
        'cluster_exists' => false,
        'mysql_installed' => true,
        'shell_installed' => true,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once()->with(
        Mockery::on(fn ($n) => $n->id === $node->id),
        Mockery::on(fn ($c) => $c->id === $cluster->id),
    );

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('createCluster')->once()->andReturn([
        'success' => true,
        'data' => ['clusterName' => 'test-cluster'],
        'raw_output' => '',
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $node->refresh();
    $cluster->refresh();

    expect($node->role)->toBe(NodeRole::Primary);
    expect($node->status)->toBe(NodeStatus::Online);
    expect($cluster->status)->toBe(ClusterStatus::Online);
    expect($cluster->last_status_json)->toBe(['clusterName' => 'test-cluster']);

    $progress = Cache::get(ProvisionClusterJob::progressKey($cluster->id));
    expect($progress['status'])->toBe('complete');
});

it('provisions with an existing cluster and fetches status', function () {
    $cluster = Cluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(ProvisionClusterJob::class, [$cluster, $node, 'rootpass', 'adminpass'])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([
        'cluster_exists' => true,
        'mysql_installed' => true,
        'shell_installed' => true,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('getClusterStatus')->once()->andReturn([
        'success' => true,
        'data' => ['clusterName' => 'existing-cluster', 'status' => 'OK'],
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $node->refresh();
    $cluster->refresh();

    expect($node->role)->toBe(NodeRole::Primary);
    expect($node->status)->toBe(NodeStatus::Online);
    expect($cluster->status)->toBe(ClusterStatus::Online);
    expect($cluster->last_status_json)->toHaveKey('clusterName', 'existing-cluster');
});

it('sets cluster to error when createCluster fails', function () {
    $cluster = Cluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(ProvisionClusterJob::class, [$cluster, $node, 'rootpass', 'adminpass'])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([
        'cluster_exists' => false,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('createCluster')->once()->andReturn([
        'success' => false,
        'data' => ['error' => 'Connection refused'],
        'raw_output' => 'Connection refused',
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $cluster->refresh();
    expect($cluster->status)->toBe(ClusterStatus::Error);

    $progress = Cache::get(ProvisionClusterJob::progressKey($cluster->id));
    expect($progress['status'])->toBe('failed');
});

it('sets cluster to error when createCluster returns data error', function () {
    $cluster = Cluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(ProvisionClusterJob::class, [$cluster, $node, 'rootpass', 'adminpass'])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([
        'cluster_exists' => false,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('createCluster')->once()->andReturn([
        'success' => true,
        'data' => ['error' => 'Cluster already exists'],
        'raw_output' => '',
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $cluster->refresh();
    expect($cluster->status)->toBe(ClusterStatus::Error);
});

it('sets cluster to error when provisionNode throws exception', function () {
    $cluster = Cluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(ProvisionClusterJob::class, [$cluster, $node, 'rootpass', 'adminpass'])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andThrow(
        new RuntimeException('SSH connection failed')
    );

    $firewallService = Mockery::mock(FirewallService::class);
    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $cluster->refresh();
    expect($cluster->status)->toBe(ClusterStatus::Error);

    $progress = Cache::get(ProvisionClusterJob::progressKey($cluster->id));
    expect($progress['status'])->toBe('failed');
    expect(collect($progress['steps'])->last()['message'])->toContain('SSH connection failed');
});

it('stores progress steps in cache during provisioning', function () {
    $cluster = Cluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);

    $job = Mockery::mock(ProvisionClusterJob::class, [$cluster, $node, 'rootpass', 'adminpass'])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $job->shouldReceive('provisionNode')->once()->andReturn([
        'cluster_exists' => false,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureDbNode')->once();

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('createCluster')->once()->andReturn([
        'success' => true,
        'data' => ['clusterName' => 'test-cluster'],
        'raw_output' => '',
    ]);

    $provisionService = Mockery::mock(NodeProvisionService::class);
    $sshService = Mockery::mock(SshService::class);

    $job->handle($provisionService, $firewallService, $mysqlShell, $sshService);

    $progress = Cache::get(ProvisionClusterJob::progressKey($cluster->id));

    expect($progress)->toHaveKey('steps');
    expect($progress)->toHaveKey('status');
    expect($progress['status'])->toBe('complete');
    expect(count($progress['steps']))->toBeGreaterThan(0);

    // All steps should be resolved (no 'running' status left)
    $runningSteps = collect($progress['steps'])->where('status', 'running');
    expect($runningSteps)->toBeEmpty();
});

it('returns getRootPassword as the mysql root password', function () {
    $cluster = Cluster::factory()->create();
    $node = Node::factory()->create(['cluster_id' => $cluster->id]);
    $job = new ProvisionClusterJob($cluster, $node, 'my-root-pass', 'admin-pass');

    $reflection = new ReflectionMethod($job, 'getRootPassword');
    $result = $reflection->invoke($job, $cluster, $node);

    expect($result)->toBe('my-root-pass');
});
