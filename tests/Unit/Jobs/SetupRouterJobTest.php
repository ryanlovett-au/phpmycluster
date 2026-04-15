<?php

use App\Enums\MysqlNodeRole;
use App\Enums\MysqlNodeStatus;
use App\Jobs\SetupRouterJob;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\FirewallService;
use App\Services\MysqlProvisionService;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

it('has a timeout of 900 seconds', function () {
    $cluster = MysqlCluster::factory()->create();
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);
    $job = new SetupRouterJob($cluster, $node);

    expect($job->timeout)->toBe(900);
});

it('has tries set to 1', function () {
    $cluster = MysqlCluster::factory()->create();
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);
    $job = new SetupRouterJob($cluster, $node);

    expect($job->tries)->toBe(1);
});

it('returns the correct progress key format', function () {
    $key = SetupRouterJob::progressKey(15);

    expect($key)->toBe('setup_router_progress_15');
});

it('sets up a router successfully when not already installed', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode, 'any');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => true,
        'hostname' => 'router-host',
        'os' => 'Ubuntu 22.04',
    ]);
    $sshService->shouldReceive('exec')->andReturn(['output' => '', 'exit_code' => 0]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('getRouterStatus')->once()->andReturn([
        'running' => false,
    ]);
    $provisionService->shouldReceive('installMysqlRouter')->once()->andReturn([
        'installed' => true,
        'version' => '8.4.0',
    ]);
    $provisionService->shouldReceive('bootstrapRouter')->once()->andReturn([
        'success' => true,
        'output' => 'Router bootstrapped.',
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureAccessNode')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $routerNode->refresh();
    expect($routerNode->status)->toBe(MysqlNodeStatus::Online);
    expect($routerNode->mysql_router_installed)->toBeTrue();

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('complete');
});

it('sets up a router successfully when already installed and running', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => true,
        'hostname' => 'router-host',
        'os' => 'Ubuntu 22.04',
    ]);
    $sshService->shouldReceive('exec')->andReturn(['output' => '', 'exit_code' => 0]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('getRouterStatus')->once()->andReturn([
        'running' => true,
    ]);
    $provisionService->shouldReceive('bootstrapRouter')->once()->andReturn([
        'success' => true,
        'output' => 'Bootstrapped.',
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureAccessNode')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $routerNode->refresh();
    expect($routerNode->status)->toBe(MysqlNodeStatus::Online);
    expect($routerNode->mysql_router_installed)->toBeTrue();

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('complete');
});

it('sets node to error when SSH connection fails', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => false,
        'error' => 'Connection timed out',
    ]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $firewallService = Mockery::mock(FirewallService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $routerNode->refresh();
    expect($routerNode->status)->toBe(MysqlNodeStatus::Error);

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('failed');
    expect(collect($progress['steps'])->last()['message'])->toContain('Connection timed out');
});

it('sets node to error when OS cannot be detected', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => true,
        'hostname' => 'router-host',
        'os' => null,
    ]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $firewallService = Mockery::mock(FirewallService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $routerNode->refresh();
    expect($routerNode->status)->toBe(MysqlNodeStatus::Error);

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('failed');
    expect(collect($progress['steps'])->last()['message'])->toContain('Unable to detect OS');
});

it('sets node to error when router installation fails', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => true,
        'hostname' => 'router-host',
        'os' => 'Ubuntu 22.04',
    ]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('getRouterStatus')->once()->andReturn([
        'running' => false,
    ]);
    $provisionService->shouldReceive('installMysqlRouter')->once()->andReturn([
        'installed' => false,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $routerNode->refresh();
    expect($routerNode->status)->toBe(MysqlNodeStatus::Error);

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('failed');
});

it('sets node to error when no primary node exists for bootstrap', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    // No primary node
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => true,
        'hostname' => 'router-host',
        'os' => 'Ubuntu 22.04',
    ]);
    $sshService->shouldReceive('exec')->andReturn(['output' => '', 'exit_code' => 0]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('getRouterStatus')->once()->andReturn([
        'running' => true,
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureAccessNode')->once();

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $routerNode->refresh();
    expect($routerNode->status)->toBe(MysqlNodeStatus::Error);

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('failed');
    expect(collect($progress['steps'])->last()['message'])->toContain('No primary node found');
});

it('sets node to error when bootstrap fails', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => true,
        'hostname' => 'router-host',
        'os' => 'Ubuntu 22.04',
    ]);
    $sshService->shouldReceive('exec')->andReturn(['output' => '', 'exit_code' => 0]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('getRouterStatus')->once()->andReturn([
        'running' => true,
    ]);
    $provisionService->shouldReceive('bootstrapRouter')->once()->andReturn([
        'success' => false,
        'output' => 'Error: could not connect to primary',
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureAccessNode')->once();

    Log::shouldReceive('error')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $routerNode->refresh();
    expect($routerNode->status)->toBe(MysqlNodeStatus::Error);

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('failed');
    expect(collect($progress['steps'])->last()['message'])->toContain('could not connect to primary');
});

it('stores progress steps in cache and resolves running steps', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);
    $routerNode = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'role' => MysqlNodeRole::Access,
    ]);

    $job = new SetupRouterJob($cluster, $routerNode);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('testConnection')->once()->andReturn([
        'success' => true,
        'hostname' => 'router-host',
        'os' => 'Ubuntu 22.04',
    ]);
    $sshService->shouldReceive('exec')->andReturn(['output' => '', 'exit_code' => 0]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('getRouterStatus')->once()->andReturn([
        'running' => false,
    ]);
    $provisionService->shouldReceive('installMysqlRouter')->once()->andReturn([
        'installed' => true,
        'version' => '8.4.0',
    ]);
    $provisionService->shouldReceive('bootstrapRouter')->once()->andReturn([
        'success' => true,
        'output' => 'Bootstrapped.',
    ]);

    $firewallService = Mockery::mock(FirewallService::class);
    $firewallService->shouldReceive('configureAccessNode')->once();

    $job->handle($provisionService, $firewallService, $sshService);

    $progress = Cache::get(SetupRouterJob::progressKey($routerNode->id));
    expect($progress['status'])->toBe('complete');
    expect(count($progress['steps']))->toBeGreaterThan(5);

    // All steps should be resolved (no 'running' status left)
    $runningSteps = collect($progress['steps'])->where('status', 'running');
    expect($runningSteps)->toBeEmpty();
});

it('defaults allowFrom to any', function () {
    $cluster = MysqlCluster::factory()->create();
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);
    $job = new SetupRouterJob($cluster, $node);

    expect($job->allowFrom)->toBe('any');
});

it('accepts custom allowFrom value', function () {
    $cluster = MysqlCluster::factory()->create();
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);
    $job = new SetupRouterJob($cluster, $node, '192.168.1.0/24');

    expect($job->allowFrom)->toBe('192.168.1.0/24');
});
