<?php

use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\MysqlShellService;
use App\Services\SshService;

// --- Existing tests ---

it('extracts JSON from mixed output', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'extractJson');
    $method->setAccessible(true);

    $output = "WARNING: Using a password on the command line is insecure.\n{\"status\": \"OK\", \"count\": 3}";
    $result = $method->invoke($service, $output);

    expect($result)->toBe(['status' => 'OK', 'count' => 3]);
});

it('extracts JSON array from mixed output', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'extractJson');
    $method->setAccessible(true);

    $output = "Some banner text\n[\"db1\", \"db2\", \"db3\"]";
    $result = $method->invoke($service, $output);

    expect($result)->toBe(['db1', 'db2', 'db3']);
});

it('returns null when no JSON is found', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'extractJson');
    $method->setAccessible(true);

    $result = $method->invoke($service, 'no json here at all');

    expect($result)->toBeNull();
});

it('calls exec with mysqlsh command for getClusterStatus', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'mysqlsh')
                && str_contains($command, '--js')
                && str_contains($command, 'dba.getCluster')
                && str_contains($command, 'status')
                && $action === 'cluster.status';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->getClusterStatus($node, 'testpassword');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveKey('defaultReplicaSet');
});

it('calls exec with correct command for listUsers', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'mysqlsh')
                && str_contains($command, 'mysql.user')
                && $action === 'user.list';
        })
        ->andReturn([
            'success' => true,
            'output' => '[{"user": "app_user", "host": "%"}]',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->listUsers($node, 'testpassword');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBeArray();
});

it('calls exec with correct command for createUser', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'mysqlsh')
                && str_contains($command, 'CREATE USER')
                && str_contains($command, 'newuser')
                && str_contains($command, 'GRANT')
                && $action === 'user.create';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->createUser($node, 'testpassword', 'newuser', 'userpass', '%', '*', 'ALL PRIVILEGES');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['status' => 'ok']);
});

it('calls exec with correct command for dropUser', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'mysqlsh')
                && str_contains($command, 'DROP USER')
                && str_contains($command, 'olduser')
                && $action === 'user.drop';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->dropUser($node, 'testpassword', 'olduser', '%');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['status' => 'ok']);
});

it('calls exec with correct command for listDatabases', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'mysqlsh')
                && str_contains($command, 'SHOW DATABASES')
                && $action === 'db.list';
        })
        ->andReturn([
            'success' => true,
            'output' => '["myapp", "analytics"]',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->listDatabases($node, 'testpassword');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['myapp', 'analytics']);
});

it('calls exec with correct command for createDatabase', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'mysqlsh')
                && str_contains($command, 'CREATE DATABASE')
                && str_contains($command, 'newdb')
                && $action === 'db.create';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->createDatabase($node, 'testpassword', 'newdb');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['status' => 'ok']);
});

it('passes password as MYSQLSH_PASSWORD env var in runJs', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command) {
            return str_contains($command, 'MYSQLSH_PASSWORD=');
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "OK"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->getClusterStatus($node, 'mypass');

    expect($result['success'])->toBeTrue();
});

// --- New tests ---

it('runJs returns parsed data from JSON output', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => true,
            'output' => "WARNING: something\n{\"key\": \"value\"}",
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->runJs($node, 'print("test")', 'test.action', 'pass');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['key' => 'value'])
        ->and($result['raw_output'])->toContain('WARNING');
});

it('runJs returns null data when no JSON in output', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => true,
            'output' => 'no json here',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->runJs($node, 'print("test")', 'test.nojson');

    expect($result['data'])->toBeNull();
});

it('runJs omits MYSQLSH_PASSWORD when no password provided', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command) {
            return ! str_contains($command, 'MYSQLSH_PASSWORD=');
        })
        ->andReturn([
            'success' => true,
            'output' => '{"ok": true}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->runJs($node, 'print("hi")', 'test.nopass');

    expect($result['success'])->toBeTrue();
});

it('checkInstanceConfiguration calls runJs with correct command', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'dba.checkInstanceConfiguration')
                && str_contains($command, 'clusteradmin@localhost:3306')
                && $action === 'cluster.check_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->checkInstanceConfiguration($node, 'testpass');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['status' => 'ok']);
});

it('configureInstance tries socket auth first', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock')
                && str_contains($command, 'dba.configureInstance')
                && str_contains($command, 'clusterAdmin')
                && $action === 'cluster.configure_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->configureInstance($node, 'rootpass', 'clusteradmin', 'clusterpass');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['status' => 'ok']);
});

it('configureInstance falls back to TCP on Access denied', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    // First call: socket auth returns Access denied
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return $action === 'cluster.configure_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"error": "Access denied for user \'root\'@\'localhost\'"}',
            'exit_code' => 0,
        ]);

    // Second call: TCP fallback
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return $action === 'cluster.configure_instance_tcp'
                && str_contains($command, 'root@localhost:3306');
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->configureInstance($node, 'rootpass', 'clusteradmin', 'clusterpass');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['status' => 'ok']);
});

it('configureInstance does not fall back on non-Access-denied errors', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => true,
            'output' => '{"error": "Unknown host"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->configureInstance($node, 'rootpass', 'clusteradmin', 'clusterpass');

    // Should not fall back, just return the error
    expect($result['data'])->toBe(['error' => 'Unknown host']);
});

it('createCluster uses MYSQL communication stack without ipAllowlist', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'communication_stack' => 'MYSQL',
        'name' => 'test-cluster',
    ]);
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'communicationStack')
                && str_contains($command, 'MYSQL')
                && ! str_contains($command, 'ipAllowlist')
                && str_contains($command, 'dba.createCluster')
                && str_contains($command, 'test-cluster')
                && $action === 'cluster.create';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->createCluster($node, $cluster, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('createCluster uses XCOM communication stack with ipAllowlist', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'communication_stack' => 'XCOM',
        'name' => 'xcom-cluster',
    ]);
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'communicationStack')
                && str_contains($command, 'XCOM')
                && str_contains($command, 'ipAllowlist')
                && str_contains($command, 'dba.createCluster')
                && $action === 'cluster.create';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->createCluster($node, $cluster, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('addInstance uses MYSQL stack without ipAllowlist', function () {
    $cluster = MysqlCluster::factory()->online()->create(['communication_stack' => 'MYSQL']);
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id, 'mysql_port' => 3306]);
    $newNode = MysqlNode::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.2', 'mysql_port' => 3306]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($primary) {
            return $n->id === $primary->id
                && str_contains($command, 'addInstance')
                && str_contains($command, '10.0.0.2:3306')
                && str_contains($command, 'recoveryMethod')
                && ! str_contains($command, 'ipAllowlist')
                && $action === 'cluster.add_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->addInstance($primary, $newNode, $cluster, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('addInstance uses XCOM stack with ipAllowlist', function () {
    $cluster = MysqlCluster::factory()->online()->create(['communication_stack' => 'XCOM']);
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id, 'mysql_port' => 3306]);
    $newNode = MysqlNode::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.2', 'mysql_port' => 3306]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'ipAllowlist')
                && $action === 'cluster.add_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->addInstance($primary, $newNode, $cluster, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('removeInstance sends correct command without force', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id, 'mysql_port' => 3306]);
    $target = MysqlNode::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.3', 'mysql_port' => 3306]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($primary) {
            return $n->id === $primary->id
                && str_contains($command, 'c.removeInstance')
                && str_contains($command, '10.0.0.3:3306')
                && ! str_contains($command, 'force')
                && $action === 'cluster.remove_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "removed"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->removeInstance($primary, $target, 'testpass');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toBe(['status' => 'removed']);
});

it('removeInstance sends correct command with force', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id, 'mysql_port' => 3306]);
    $target = MysqlNode::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.3', 'mysql_port' => 3306]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'force: true')
                && $action === 'cluster.remove_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "removed"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->removeInstance($primary, $target, 'testpass', force: true);

    expect($result['success'])->toBeTrue();
});

it('rejoinInstance sends correct command', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $primary = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id, 'mysql_port' => 3306]);
    $target = MysqlNode::factory()->secondary()->create(['cluster_id' => $cluster->id, 'host' => '10.0.0.4', 'mysql_port' => 3306]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($primary) {
            return $n->id === $primary->id
                && str_contains($command, 'c.rejoinInstance')
                && str_contains($command, '10.0.0.4:3306')
                && $action === 'cluster.rejoin_instance';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->rejoinInstance($primary, $target, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('rescanCluster sends correct command', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'c.rescan')
                && str_contains($command, 'interactive: false')
                && $action === 'cluster.rescan';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->rescanCluster($node, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('forceQuorum sends correct command', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'c.forceQuorumUsingPartitionOf')
                && $action === 'cluster.force_quorum';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK_NO_TOLERANCE"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->forceQuorum($node, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('rebootCluster sends correct command', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'dba.rebootClusterFromCompleteOutage')
                && $action === 'cluster.reboot';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->rebootCluster($node, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('switchToSinglePrimary sends correct command without target host', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'c.switchToSinglePrimaryMode()')
                && $action === 'cluster.switch_primary';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->switchToSinglePrimary($node, 'testpass');

    expect($result['success'])->toBeTrue();
});

it('switchToSinglePrimary sends correct command with target host', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'switchToSinglePrimaryMode')
                && str_contains($command, '10.0.0.5')
                && $action === 'cluster.switch_primary';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"defaultReplicaSet": {"status": "OK"}}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->switchToSinglePrimary($node, 'testpass', '10.0.0.5');

    expect($result['success'])->toBeTrue();
});

it('getUserGrants sends correct command', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'SHOW GRANTS FOR')
                && str_contains($command, 'appuser')
                && $action === 'user.grants';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"grants": ["GRANT ALL PRIVILEGES ON *.* TO \'appuser\'@\'%\'"]}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->getUserGrants($node, 'testpass', 'appuser', '%');

    expect($result['success'])->toBeTrue()
        ->and($result['data'])->toHaveKey('grants');
});

it('updateUser updates password only', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'ALTER USER')
                && str_contains($command, 'newpass')
                && ! str_contains($command, 'REVOKE')
                && $action === 'user.update';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->updateUser($node, 'testpass', 'myuser', '%', 'newpass', null, null);

    expect($result['success'])->toBeTrue();
});

it('updateUser updates privileges only', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return ! str_contains($command, 'ALTER USER')
                && str_contains($command, 'REVOKE ALL PRIVILEGES')
                && str_contains($command, 'GRANT SELECT')
                && $action === 'user.update';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->updateUser($node, 'testpass', 'myuser', '%', null, 'mydb', 'SELECT');

    expect($result['success'])->toBeTrue();
});

it('updateUser updates both password and privileges', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, 'ALTER USER')
                && str_contains($command, 'REVOKE ALL PRIVILEGES')
                && str_contains($command, 'GRANT ALL PRIVILEGES')
                && $action === 'user.update';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->updateUser($node, 'testpass', 'myuser', '%', 'newpass', '*', 'ALL PRIVILEGES');

    expect($result['success'])->toBeTrue();
});

it('createUser scopes to specific database', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) {
            return str_contains($command, '`mydb`.*')
                && str_contains($command, 'SELECT, INSERT')
                && $action === 'user.create';
        })
        ->andReturn([
            'success' => true,
            'output' => '{"status": "ok"}',
            'exit_code' => 0,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->createUser($node, 'testpass', 'dbuser', 'pass', '%', 'mydb', 'SELECT, INSERT');

    expect($result['success'])->toBeTrue();
});

it('extractJson handles invalid JSON gracefully', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'extractJson');
    $method->setAccessible(true);

    $result = $method->invoke($service, '{not valid json at all!!!}');

    expect($result)->toBeNull();
});

it('extractJson handles JSON embedded in banner output', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'extractJson');
    $method->setAccessible(true);

    $output = "MySQL Shell 8.4.0\nCopyright (c) Oracle\nWARNING: something\n{\"defaultReplicaSet\": {\"name\": \"default\", \"status\": \"OK\"}}";
    $result = $method->invoke($service, $output);

    expect($result)->toBe(['defaultReplicaSet' => ['name' => 'default', 'status' => 'OK']]);
});

it('runJs handles failed ssh exec', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => 'Connection refused',
            'exit_code' => -1,
        ]);

    $service = new MysqlShellService($sshMock);
    $result = $service->runJs($node, 'print("test")', 'test.fail', 'pass');

    expect($result['success'])->toBeFalse()
        ->and($result['data'])->toBeNull()
        ->and($result['exit_code'])->toBe(-1);
});

// --- Input validation / sanitisation tests ---

it('validateIdentifier accepts valid identifiers', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'validateIdentifier');
    $method->setAccessible(true);

    expect($method->invoke($service, 'app_user', 'Username'))->toBe('app_user')
        ->and($method->invoke($service, 'my-database', 'Database'))->toBe('my-database')
        ->and($method->invoke($service, 'user@%', 'User host'))->toBe('user@%')
        ->and($method->invoke($service, 'db_v2.0', 'Database'))->toBe('db_v2.0');
});

it('validateIdentifier rejects identifiers with shell injection characters', function (string $badInput) {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'validateIdentifier');
    $method->setAccessible(true);

    $method->invoke($service, $badInput, 'Test');
})->with([
    'semicolon' => ['user; DROP TABLE'],
    'backtick' => ['user`; --'],
    'single quote' => ["user' OR '1'='1"],
    'double quote' => ['user" OR "1"="1'],
    'dollar sign' => ['$(whoami)'],
    'pipe' => ['user|cat /etc/passwd'],
    'ampersand' => ['user && rm -rf /'],
    'newline' => ["user\nDROP TABLE"],
    'space' => ['user name'],
])->throws(InvalidArgumentException::class);

it('sanitisePassword escapes special characters', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'sanitisePassword');
    $method->setAccessible(true);

    // Single quotes, double quotes, backslashes should be escaped
    $result = $method->invoke($service, "pass'word");
    expect($result)->toBe("pass\\'word");

    $result = $method->invoke($service, 'pass"word');
    expect($result)->toBe('pass\\"word');

    $result = $method->invoke($service, 'pass\\word');
    expect($result)->toBe('pass\\\\word');
});

it('buildDbScope returns wildcard for star', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'buildDbScope');
    $method->setAccessible(true);

    expect($method->invoke($service, '*'))->toBe('*.*');
});

it('buildDbScope wraps database name in backticks', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'buildDbScope');
    $method->setAccessible(true);

    expect($method->invoke($service, 'mydb'))->toBe('`mydb`.*');
});

it('buildDbScope rejects invalid database names', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $method = new ReflectionMethod(MysqlShellService::class, 'buildDbScope');
    $method->setAccessible(true);

    $method->invoke($service, 'db; DROP TABLE users');
})->throws(InvalidArgumentException::class);

it('createUser rejects usernames with injection characters', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $service->createUser($node, 'adminpass', "evil'; DROP TABLE users; --", 'userpass', '%', '*', 'ALL PRIVILEGES');
})->throws(InvalidArgumentException::class);

it('dropUser rejects usernames with injection characters', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlShellService($sshMock);

    $service->dropUser($node, 'adminpass', "evil'; DROP TABLE users; --", '%');
})->throws(InvalidArgumentException::class);
