<?php

use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\FirewallService;
use App\Services\SshService;

// --- Existing tests ---

it('calls correct SSH command for getStatus', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'ufw status')
                && $action === 'firewall.status'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => "/usr/sbin/ufw\nStatus: active\nTo                         Action      From\n22/tcp                     ALLOW       Anywhere",
            'exit_code' => 0,
        ]);

    $service = new FirewallService($sshMock);
    $result = $service->getStatus($node);

    expect($result['installed'])->toBeTrue()
        ->and($result['active'])->toBeTrue();
});

it('detects UFW not installed', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => '',
            'exit_code' => 1,
        ]);

    $service = new FirewallService($sshMock);
    $result = $service->getStatus($node);

    expect($result['installed'])->toBeFalse()
        ->and($result['active'])->toBeFalse();
});

it('detects UFW inactive', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => true,
            'output' => "/usr/sbin/ufw\nStatus: inactive",
            'exit_code' => 0,
        ]);

    $service = new FirewallService($sshMock);
    $result = $service->getStatus($node);

    expect($result['installed'])->toBeTrue()
        ->and($result['active'])->toBeFalse();
});

it('calls correct SSH commands for installUfw', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'apt-get')
                && str_contains($command, 'ufw')
                && $action === 'firewall.install'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => 'ufw is already the newest version',
            'exit_code' => 0,
        ]);

    $service = new FirewallService($sshMock);
    $result = $service->installUfw($node);

    expect($result['success'])->toBeTrue();
});

// --- New tests ---

it('getStatus returns output in result', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => true,
            'output' => "/usr/sbin/ufw\nStatus: active\n22/tcp ALLOW Anywhere",
            'exit_code' => 0,
        ]);

    $service = new FirewallService($sshMock);
    $result = $service->getStatus($node);

    expect($result)->toHaveKey('output')
        ->and($result['output'])->toContain('Status: active');
});

it('configureDbNode opens SSH and MySQL ports for peer nodes', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node1 = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-1',
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
        'mysql_x_port' => 33060,
    ]);
    $node2 = MysqlNode::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-2',
        'host' => '10.0.0.2',
        'mysql_port' => 3306,
        'mysql_x_port' => 33060,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    // Track all exec calls
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$commands) {
            $commands[] = ['node_id' => $n->id, 'command' => $command, 'action' => $action, 'sudo' => $sudo];

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    $results = $service->configureDbNode($node1, $cluster);

    // Verify SSH allow was called
    $sshRule = collect($commands)->first(fn ($c) => str_contains($c['command'], 'ufw allow 22/tcp'));
    expect($sshRule)->not->toBeNull()
        ->and($sshRule['sudo'])->toBeTrue();

    // Verify MySQL port rule from peer
    $mysqlRule = collect($commands)->first(fn ($c) => str_contains($c['command'], 'from 10.0.0.2') && str_contains($c['command'], 'port 3306'));
    expect($mysqlRule)->not->toBeNull();

    // Verify MySQL X port rule from peer
    $mysqlXRule = collect($commands)->first(fn ($c) => str_contains($c['command'], 'from 10.0.0.2') && str_contains($c['command'], 'port 33060'));
    expect($mysqlXRule)->not->toBeNull();

    // Verify GR comm port rule from peer
    $grRule = collect($commands)->first(fn ($c) => str_contains($c['command'], 'from 10.0.0.2') && str_contains($c['command'], 'port 33061'));
    expect($grRule)->not->toBeNull();

    // Verify defaults and enable
    $denyIncoming = collect($commands)->first(fn ($c) => str_contains($c['command'], 'ufw default deny incoming'));
    expect($denyIncoming)->not->toBeNull();

    $allowOutgoing = collect($commands)->first(fn ($c) => str_contains($c['command'], 'ufw default allow outgoing'));
    expect($allowOutgoing)->not->toBeNull();

    $enable = collect($commands)->first(fn ($c) => str_contains($c['command'], 'ufw --force enable'));
    expect($enable)->not->toBeNull();

    expect($results)->toBeArray()
        ->and(count($results))->toBeGreaterThan(0);
});

it('configureDbNode skips self when iterating peers', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node1 = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-1',
        'host' => '10.0.0.1',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$commands) {
            $commands[] = $command;

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    $service->configureDbNode($node1, $cluster);

    // Should not contain a rule "from 10.0.0.1" since that's the node itself
    $selfRules = collect($commands)->filter(fn ($c) => str_contains($c, 'from 10.0.0.1'));
    expect($selfRules)->toBeEmpty();
});

it('configureDbNode opens MySQL port for access nodes', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $dbNode = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);
    $accessNode = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-1',
        'host' => '10.0.0.10',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$commands) {
            $commands[] = $command;

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    $service->configureDbNode($dbNode, $cluster);

    // Verify MySQL port opened from access node
    $accessRule = collect($commands)->first(fn ($c) => str_contains($c, 'from 10.0.0.10') && str_contains($c, 'port 3306'));
    expect($accessRule)->not->toBeNull();
});

it('configureAccessNode opens SSH and router ports', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.10',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$commands) {
            $commands[] = $command;

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    $results = $service->configureAccessNode($node, $cluster);

    // SSH
    $sshRule = collect($commands)->first(fn ($c) => str_contains($c, 'ufw allow 22/tcp'));
    expect($sshRule)->not->toBeNull();

    // Router RW port 6446
    $rwRule = collect($commands)->first(fn ($c) => str_contains($c, 'port 6446') && str_contains($c, '127.0.0.1'));
    expect($rwRule)->not->toBeNull();

    // Router RO port 6447
    $roRule = collect($commands)->first(fn ($c) => str_contains($c, 'port 6447') && str_contains($c, '127.0.0.1'));
    expect($roRule)->not->toBeNull();

    // Enable
    $enable = collect($commands)->first(fn ($c) => str_contains($c, 'ufw --force enable'));
    expect($enable)->not->toBeNull();

    expect($results)->toBeArray();
});

it('configureAccessNode uses custom allowFrom', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$commands) {
            $commands[] = $command;

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    $service->configureAccessNode($node, $cluster, '192.168.1.0/24');

    // Should use custom allowFrom instead of 127.0.0.1
    $rwRule = collect($commands)->first(fn ($c) => str_contains($c, 'port 6446') && str_contains($c, '192.168.1.0/24'));
    expect($rwRule)->not->toBeNull();

    $roRule = collect($commands)->first(fn ($c) => str_contains($c, 'port 6447') && str_contains($c, '192.168.1.0/24'));
    expect($roRule)->not->toBeNull();
});

it('allowNewNodeOnCluster adds firewall rules on existing nodes', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $existingNode = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);
    $newNode = MysqlNode::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'name' => 'db-new',
        'host' => '10.0.0.3',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$commands) {
            $commands[] = ['node_id' => $n->id, 'command' => $command];

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    $results = $service->allowNewNodeOnCluster($cluster, $newNode);

    // Should run commands on existing node, not on new node
    $existingNodeCommands = collect($commands)->filter(fn ($c) => $c['node_id'] === $existingNode->id);
    expect($existingNodeCommands)->not->toBeEmpty();

    // Should have MySQL port rule from new node
    $mysqlRule = $existingNodeCommands->first(fn ($c) => str_contains($c['command'], 'from 10.0.0.3') && str_contains($c['command'], 'port 3306'));
    expect($mysqlRule)->not->toBeNull();

    // Should have GR comm port rule from new node
    $grRule = $existingNodeCommands->first(fn ($c) => str_contains($c['command'], 'from 10.0.0.3') && str_contains($c['command'], 'port 33061'));
    expect($grRule)->not->toBeNull();

    expect($results)->toBeArray();
});

it('allowNewNodeOnCluster skips self when new node already in dbNodes', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$commands) {
            $commands[] = $command;

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    // Pass the same node as both existing and new - should skip itself
    $results = $service->allowNewNodeOnCluster($cluster, $node);

    expect($commands)->toBeEmpty()
        ->and($results)->toBeEmpty();
});

it('allowNewNodeOnCluster updates multiple existing nodes', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node1 = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_port' => 3306,
    ]);
    $node2 = MysqlNode::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.2',
        'mysql_port' => 3306,
    ]);
    $newNode = MysqlNode::factory()->secondary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.3',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $targetNodeIds = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$targetNodeIds) {
            $targetNodeIds[] = $n->id;

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new FirewallService($sshMock);
    $results = $service->allowNewNodeOnCluster($cluster, $newNode);

    // Should have updated both existing nodes
    expect(collect($targetNodeIds)->unique()->values()->toArray())->toContain($node1->id, $node2->id)
        ->and(collect($targetNodeIds))->not->toContain($newNode->id);
});
