<?php

use App\Models\RedisCluster;
use App\Models\RedisNode;
use App\Models\Server;
use App\Services\RedisCliService;
use App\Services\SshService;

it('pings a redis node successfully', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd, $action) {
            return str_contains($cmd, 'redis-cli') && str_contains($cmd, 'PING') && $action === 'redis.ping';
        })
        ->andReturn(['success' => true, 'output' => 'PONG', 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $result = $service->ping($node, 'testpass');

    expect($result['success'])->toBeTrue()
        ->and($result['output'])->toBe('PONG');
});

it('includes auth flag when password is provided', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return str_contains($cmd, '-a') && str_contains($cmd, '--no-auth-warning');
        })
        ->andReturn(['success' => true, 'output' => 'PONG', 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $service->ping($node, 'mypassword');
});

it('omits auth flag when no password', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return ! str_contains($cmd, '-a');
        })
        ->andReturn(['success' => true, 'output' => 'PONG', 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $service->ping($node);
});

it('gets info with parsed data', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $infoOutput = "# Replication\nrole:master\nconnected_slaves:2\n";

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn(['success' => true, 'output' => $infoOutput, 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $result = $service->getInfo($node, 'replication', 'pass');

    expect($result['success'])->toBeTrue()
        ->and($result['data']['role'])->toBe('master')
        ->and($result['data']['connected_slaves'])->toBe('2');
});

it('gets replication info', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return str_contains($cmd, 'INFO') && str_contains($cmd, 'replication');
        })
        ->andReturn(['success' => true, 'output' => "role:master\n", 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $result = $service->getReplicationInfo($node, 'pass');

    expect($result['success'])->toBeTrue()
        ->and($result['data']['role'])->toBe('master');
});

it('gets sentinel masters using sentinel port', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
        'sentinel_port' => 26379,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return str_contains($cmd, '-p 26379') && str_contains($cmd, 'SENTINEL masters');
        })
        ->andReturn(['success' => true, 'output' => "name\nmymaster\nip\n10.0.0.1\nport\n6379\n", 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $result = $service->getSentinelMasters($node, 'pass');

    expect($result['success'])->toBeTrue()
        ->and($result['data']['name'])->toBe('mymaster')
        ->and($result['data']['ip'])->toBe('10.0.0.1');
});

it('triggers sentinel failover', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return str_contains($cmd, 'SENTINEL failover');
        })
        ->andReturn(['success' => true, 'output' => 'OK', 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $result = $service->sentinelFailover($node, 'mymaster', 'pass');

    expect($result['success'])->toBeTrue()
        ->and($result['output'])->toBe('OK');
});

it('runs config set command', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return str_contains($cmd, 'CONFIG SET');
        })
        ->andReturn(['success' => true, 'output' => 'OK', 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $result = $service->configSet($node, 'maxmemory', '256mb', 'pass');

    expect($result['success'])->toBeTrue();
});

it('runs config rewrite command', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return str_contains($cmd, 'CONFIG REWRITE');
        })
        ->andReturn(['success' => true, 'output' => 'OK', 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $result = $service->configRewrite($node, 'pass');

    expect($result['success'])->toBeTrue();
});

it('handles failed ssh exec gracefully', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn(['success' => false, 'output' => 'Connection refused', 'exit_code' => 1]);

    $service = new RedisCliService($sshMock);
    $result = $service->ping($node, 'pass');

    expect($result['success'])->toBeFalse()
        ->and($result['exit_code'])->toBe(1);
});

it('uses correct host and port in redis-cli command', function () {
    $cluster = RedisCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '192.168.1.100']);
    $node = RedisNode::factory()->master()->create([
        'server_id' => $server->id,
        'redis_cluster_id' => $cluster->id,
        'redis_port' => 6380,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function ($connectable, $cmd) {
            return str_contains($cmd, '-h 192.168.1.100') && str_contains($cmd, '-p 6380');
        })
        ->andReturn(['success' => true, 'output' => 'PONG', 'exit_code' => 0]);

    $service = new RedisCliService($sshMock);
    $service->ping($node);
});
