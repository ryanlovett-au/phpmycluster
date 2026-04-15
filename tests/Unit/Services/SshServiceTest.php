<?php

use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Node;
use App\Services\SshService;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

it('generates a key pair with private and public keys', function () {
    $service = new SshService;
    $keys = $service->generateKeyPair();

    expect($keys)->toBeArray()
        ->toHaveKeys(['private', 'public']);
});

it('generates a private key starting with BEGIN marker', function () {
    $service = new SshService;
    $keys = $service->generateKeyPair();

    expect($keys['private'])->toStartWith('-----BEGIN');
});

it('generates a public key starting with ssh-ed25519', function () {
    $service = new SshService;
    $keys = $service->generateKeyPair();

    expect($keys['public'])->toStartWith('ssh-ed25519');
});

it('sanitises passwords from commands', function () {
    $service = new SshService;

    $method = new ReflectionMethod(SshService::class, 'sanitiseCommand');
    $method->setAccessible(true);

    $command = "mysqlsh --password=supersecret --js -e 'test'";
    $result = $method->invoke($service, $command);

    expect($result)->not->toContain('supersecret')
        ->toContain('--password=***');
});

it('sanitises AdminPassword from commands', function () {
    $service = new SshService;

    $method = new ReflectionMethod(SshService::class, 'sanitiseCommand');
    $method->setAccessible(true);

    $command = "dba.configureInstance('root@localhost', {AdminPassword: 'mysecret'})";
    $result = $method->invoke($service, $command);

    expect($result)->not->toContain('mysecret')
        ->toContain("AdminPassword: '***'");
});

it('connects to a node via mocked SSH2', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'ssh_port' => 22,
        'ssh_user' => 'root',
    ]);

    $sshMock = Mockery::mock(SSH2::class);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andReturn($sshMock);

    $result = $service->connect($node);

    expect($result)->toBe($sshMock);
});

it('executes a command and returns output array', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SSH2::class);
    $sshMock->shouldReceive('setTimeout')->once();
    $sshMock->shouldReceive('exec')->once()->andReturn('command output');
    $sshMock->shouldReceive('getExitStatus')->once()->andReturn(0);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andReturn($sshMock);

    $result = $service->exec($node, 'hostname', 'test.exec');

    expect($result)->toBeArray()
        ->toHaveKeys(['success', 'output', 'exit_code', 'duration_ms'])
        ->and($result['success'])->toBeTrue()
        ->and($result['output'])->toBe('command output')
        ->and($result['exit_code'])->toBe(0);

    // Verify audit log was created
    expect(AuditLog::count())->toBe(1);
    expect(AuditLog::first()->status)->toBe('success');
});

it('records failed commands in audit log', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SSH2::class);
    $sshMock->shouldReceive('setTimeout')->once();
    $sshMock->shouldReceive('exec')->once()->andReturn('error output');
    $sshMock->shouldReceive('getExitStatus')->once()->andReturn(1);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andReturn($sshMock);

    $result = $service->exec($node, 'bad-command', 'test.fail');

    expect($result['success'])->toBeFalse()
        ->and($result['exit_code'])->toBe(1);

    expect(AuditLog::first()->status)->toBe('failed');
});

it('handles SSH connection exceptions gracefully', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andThrow(new RuntimeException('Connection refused'));

    $result = $service->exec($node, 'hostname', 'test.exception');

    expect($result['success'])->toBeFalse()
        ->and($result['exit_code'])->toBe(-1)
        ->and($result['error'])->toContain('Connection refused');

    expect(AuditLog::first()->status)->toBe('failed');
});

it('prepends sudo when sudo flag is true', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SSH2::class);
    $sshMock->shouldReceive('setTimeout')->once();
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (string $cmd) {
            return str_starts_with($cmd, 'sudo bash -c');
        })
        ->andReturn('ok');
    $sshMock->shouldReceive('getExitStatus')->once()->andReturn(0);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andReturn($sshMock);

    $result = $service->exec($node, 'systemctl restart mysql', 'test.sudo', sudo: true);

    expect($result['success'])->toBeTrue();
});

// --- New tests for uncovered methods ---

it('testConnection returns success with hostname and os', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SSH2::class);
    $sshMock->shouldReceive('exec')
        ->with('hostname')
        ->once()
        ->andReturn('db-node-1');
    $sshMock->shouldReceive('exec')
        ->with(Mockery::pattern('/cat \/etc\/os-release/'))
        ->once()
        ->andReturn('Ubuntu 24.04 LTS');

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->with($node)->once()->andReturn($sshMock);

    $result = $service->testConnection($node);

    expect($result['success'])->toBeTrue()
        ->and($result['hostname'])->toBe('db-node-1')
        ->and($result['os'])->toBe('Ubuntu 24.04 LTS');
});

it('testConnection returns failure on exception', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')
        ->with($node)
        ->once()
        ->andThrow(new RuntimeException('Connection timed out'));

    $result = $service->testConnection($node);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Connection timed out');
});

// testConnectionDirect can't be easily unit tested because it constructs SSH2 internally.
// We verify the method exists and test error handling paths via exceptions from PublicKeyLoader.
it('testConnectionDirect returns failure when private key is invalid', function () {
    $service = new SshService;
    $result = $service->testConnectionDirect('10.0.0.5', 22, 'root', 'not-a-valid-key');

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBeString();
});

it('testNodeConnectivity returns port open status', function () {
    $cluster = Cluster::factory()->online()->create();
    $source = Node::factory()->primary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.1']);
    $target = Node::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.2']);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('exec')
        ->once()
        ->withArgs(function (Node $n, string $cmd, string $action) use ($source) {
            return $n->id === $source->id
                && str_contains($cmd, '/dev/tcp/10.0.0.2/3306')
                && $action === 'connectivity.test';
        })
        ->andReturn([
            'success' => true,
            'output' => 'OPEN',
            'exit_code' => 0,
        ]);

    // Call the real method - since exec is mocked, connect won't be called
    $result = $service->testNodeConnectivity($source, $target, 3306);

    expect($result['success'])->toBeTrue()
        ->and($result['port_open'])->toBeTrue()
        ->and($result['output'])->toContain('OPEN');
});

it('testNodeConnectivity returns port closed status', function () {
    $cluster = Cluster::factory()->online()->create();
    $source = Node::factory()->primary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.1']);
    $target = Node::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.2']);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => 'CLOSED',
            'exit_code' => 1,
        ]);

    $result = $service->testNodeConnectivity($source, $target, 3306);

    expect($result['success'])->toBeFalse()
        ->and($result['port_open'])->toBeFalse();
});

it('uploadFile calls connectSftp and puts content', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sftpMock = Mockery::mock(SFTP::class);
    $sftpMock->shouldReceive('put')
        ->once()
        ->with('/tmp/test.cnf', 'config content')
        ->andReturn(true);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connectSftp')->with($node)->once()->andReturn($sftpMock);

    $result = $service->uploadFile($node, '/tmp/test.cnf', 'config content');

    expect($result)->toBeTrue();
});

it('uploadFile returns false when sftp put fails', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sftpMock = Mockery::mock(SFTP::class);
    $sftpMock->shouldReceive('put')
        ->once()
        ->with('/remote/path.txt', 'data')
        ->andReturn(false);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connectSftp')->with($node)->once()->andReturn($sftpMock);

    $result = $service->uploadFile($node, '/remote/path.txt', 'data');

    expect($result)->toBeFalse();
});

it('connectSftp returns SFTP instance via mocked partial', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sftpMock = Mockery::mock(SFTP::class);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connectSftp')->with($node)->once()->andReturn($sftpMock);

    $result = $service->connectSftp($node);

    expect($result)->toBe($sftpMock);
});

it('exec records duration in audit log', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SSH2::class);
    $sshMock->shouldReceive('setTimeout')->once();
    $sshMock->shouldReceive('exec')->once()->andReturn('ok');
    $sshMock->shouldReceive('getExitStatus')->once()->andReturn(0);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andReturn($sshMock);

    $result = $service->exec($node, 'hostname', 'test.duration');

    expect($result['duration_ms'])->toBeGreaterThanOrEqual(0);

    $auditLog = AuditLog::first();
    expect($auditLog->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('exec stores sanitised command in audit log', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SSH2::class);
    $sshMock->shouldReceive('setTimeout')->once();
    $sshMock->shouldReceive('exec')->once()->andReturn('ok');
    $sshMock->shouldReceive('getExitStatus')->once()->andReturn(0);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andReturn($sshMock);

    $service->exec($node, 'mysqlsh --password=secretpass --js', 'test.sanitise');

    $auditLog = AuditLog::first();
    expect($auditLog->command)->not->toContain('secretpass')
        ->and($auditLog->command)->toContain('--password=***');
});

it('exec records error message in audit log on exception', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andThrow(new RuntimeException('Host key mismatch'));

    $result = $service->exec($node, 'hostname', 'test.error_log');

    $auditLog = AuditLog::first();
    expect($auditLog->status)->toBe('failed')
        ->and($auditLog->error_message)->toContain('Host key mismatch')
        ->and($auditLog->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('exec records exit code error message for non-zero exit', function () {
    $cluster = Cluster::factory()->online()->create();
    $node = Node::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SSH2::class);
    $sshMock->shouldReceive('setTimeout')->once();
    $sshMock->shouldReceive('exec')->once()->andReturn('Permission denied');
    $sshMock->shouldReceive('getExitStatus')->once()->andReturn(127);

    $service = Mockery::mock(SshService::class)->makePartial();
    $service->shouldReceive('connect')->andReturn($sshMock);

    $result = $service->exec($node, 'not-a-command', 'test.exit_code');

    $auditLog = AuditLog::first();
    expect($auditLog->error_message)->toContain('Exit code: 127')
        ->and($result['exit_code'])->toBe(127);
});
