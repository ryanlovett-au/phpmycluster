<?php

use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\LogStreamService;
use App\Services\SshService;

it('fetches error log via SSH', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    // First call: find the log path
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'log_error')
                && $action === 'log.find_error_log';
        })
        ->andReturn([
            'success' => true,
            'output' => '/var/log/mysql/error.log',
            'exit_code' => 0,
        ]);

    // Second call: tail the log
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) {
            return str_contains($command, 'tail -n 100')
                && str_contains($command, '/var/log/mysql/error.log')
                && $action === 'log.error_log'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => "[ERROR] Some mysql error line\n[Warning] Another warning",
            'exit_code' => 0,
        ]);

    $service = new LogStreamService($sshMock);
    $result = $service->getErrorLog($node);

    expect($result['success'])->toBeTrue()
        ->and($result['output'])->toContain('ERROR');
});

it('falls back to default error log path when output is stderr', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    // First call: find the log path — returns "stderr"
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'log_error')
                && $action === 'log.find_error_log';
        })
        ->andReturn([
            'success' => true,
            'output' => 'stderr',
            'exit_code' => 0,
        ]);

    // Second call: tail the default fallback path
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) {
            return str_contains($command, '/var/log/mysql/error.log')
                && $action === 'log.error_log'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => '[ERROR] MySQL error in fallback log',
            'exit_code' => 0,
        ]);

    $service = new LogStreamService($sshMock);
    $result = $service->getErrorLog($node);

    expect($result['success'])->toBeTrue()
        ->and($result['output'])->toContain('ERROR');
});

it('falls back to default error log path when output is empty', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    // First call: returns empty string
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'log_error')
                && $action === 'log.find_error_log';
        })
        ->andReturn([
            'success' => true,
            'output' => '',
            'exit_code' => 0,
        ]);

    // Second call: tail the default fallback path
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) {
            return str_contains($command, '/var/log/mysql/error.log')
                && $action === 'log.error_log'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => 'Fallback log content',
            'exit_code' => 0,
        ]);

    $service = new LogStreamService($sshMock);
    $result = $service->getErrorLog($node);

    expect($result['success'])->toBeTrue();
});

it('fetches slow query log via SSH', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'slow_query_log_file')
                && $action === 'log.find_slow_log';
        })
        ->andReturn([
            'success' => true,
            'output' => '/var/log/mysql/mysql-slow.log',
            'exit_code' => 0,
        ]);

    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) {
            return str_contains($command, 'tail -n 50')
                && $action === 'log.slow_log'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => '# Time: 2026-04-14\n# Query_time: 5.2',
            'exit_code' => 0,
        ]);

    $service = new LogStreamService($sshMock);
    $result = $service->getSlowLog($node, 50);

    expect($result['success'])->toBeTrue();
});

it('fetches general log via SSH', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'general_log_file')
                && $action === 'log.find_general_log';
        })
        ->andReturn([
            'success' => true,
            'output' => '/var/log/mysql/mysql.log',
            'exit_code' => 0,
        ]);

    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) {
            return str_contains($command, 'tail')
                && $action === 'log.general_log'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => 'SELECT 1',
            'exit_code' => 0,
        ]);

    $service = new LogStreamService($sshMock);
    $result = $service->getGeneralLog($node);

    expect($result['success'])->toBeTrue();
});

it('fetches systemd log for MySQL via SSH', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) {
            return str_contains($command, 'journalctl -u mysql')
                && str_contains($command, '-n 100')
                && $action === 'log.systemd'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => 'Apr 14 mysqld[1234]: ready for connections',
            'exit_code' => 0,
        ]);

    $service = new LogStreamService($sshMock);
    $result = $service->getSystemdLog($node);

    expect($result['success'])->toBeTrue();
});

it('fetches router log via SSH', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) {
            return str_contains($command, 'mysqlrouter.log')
                && $action === 'log.router'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => 'routing:read_write listening on 0.0.0.0:6446',
            'exit_code' => 0,
        ]);

    $service = new LogStreamService($sshMock);
    $result = $service->getRouterLog($node);

    expect($result['success'])->toBeTrue();
});
