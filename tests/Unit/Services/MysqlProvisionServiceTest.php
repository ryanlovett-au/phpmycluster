<?php

use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Models\Server;
use App\Services\MysqlProvisionService;
use App\Services\SshService;
use Illuminate\Support\Facades\Http;

// --- Existing tests ---

it('returns correct apt config URL format', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $url = $service->getAptConfigUrl('0.8.33-1');

    expect($url)->toBe('https://repo.mysql.com/mysql-apt-config_0.8.33-1_all.deb');
});

it('returns correct apt config URL for different versions', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $url = $service->getAptConfigUrl('0.9.0-1');

    expect($url)->toBe('https://repo.mysql.com/mysql-apt-config_0.9.0-1_all.deb');
});

it('detects OS from SSH output', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
    ]);

    $osReleaseOutput = implode("\n", [
        'PRETTY_NAME="Ubuntu 24.04 LTS"',
        'NAME="Ubuntu"',
        'VERSION_ID="24.04"',
        'VERSION="24.04 LTS (Noble Numbat)"',
        'ID=ubuntu',
        'ID_LIKE=debian',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, '/etc/os-release')
                && $action === 'provision.detect_os';
        })
        ->andReturn([
            'success' => true,
            'output' => $osReleaseOutput,
            'exit_code' => 0,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->detectOs($node);

    expect($result)->toBeArray()
        ->and($result['id'])->toBe('ubuntu')
        ->and($result['version_id'])->toBe('24.04')
        ->and($result['id_like'])->toBe('debian');
});

it('returns router status as running when active', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'systemctl is-active mysqlrouter')
                && $action === 'router.status';
        })
        ->andReturn([
            'success' => true,
            'output' => "active\nMySQLRouter 8.4.0",
            'exit_code' => 0,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->getRouterStatus($node);

    expect($result['running'])->toBeTrue()
        ->and($result['output'])->toContain('active');
});

it('returns router status as not running when inactive', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => 'inactive',
            'exit_code' => 3,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->getRouterStatus($node);

    expect($result['running'])->toBeFalse();
});

it('returns router status as not running when failed', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create([
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => 'failed',
            'exit_code' => 1,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->getRouterStatus($node);

    expect($result['running'])->toBeFalse();
});

// --- New tests ---

it('detectOs handles Debian output', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $osReleaseOutput = implode("\n", [
        'PRETTY_NAME="Debian GNU/Linux 12 (bookworm)"',
        'NAME="Debian GNU/Linux"',
        'VERSION_ID="12"',
        'ID=debian',
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')->once()->andReturn([
        'success' => true,
        'output' => $osReleaseOutput,
        'exit_code' => 0,
    ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->detectOs($node);

    expect($result['id'])->toBe('debian')
        ->and($result['version_id'])->toBe('12');
});

it('detectOs handles empty output', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')->once()->andReturn([
        'success' => true,
        'output' => '',
        'exit_code' => 0,
    ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->detectOs($node);

    expect($result)->toBeArray()
        ->and($result)->toBeEmpty();
});

it('resolveLatestAptConfigVersion returns latest version from repo', function () {
    Http::fake([
        'https://repo.mysql.com/' => Http::response(
            '<a href="mysql-apt-config_0.8.30-1_all.deb">mysql-apt-config_0.8.30-1_all.deb</a>'.
            '<a href="mysql-apt-config_0.8.33-1_all.deb">mysql-apt-config_0.8.33-1_all.deb</a>'.
            '<a href="mysql-apt-config_0.8.31-1_all.deb">mysql-apt-config_0.8.31-1_all.deb</a>',
            200
        ),
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $version = $service->resolveLatestAptConfigVersion();

    expect($version)->toBe('0.8.33-1');
});

it('resolveLatestAptConfigVersion throws on HTTP failure', function () {
    Http::fake([
        'https://repo.mysql.com/' => Http::response('Server Error', 500),
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $service->resolveLatestAptConfigVersion();
})->throws(RuntimeException::class, 'Failed to fetch MySQL repo index');

it('resolveLatestAptConfigVersion throws when no packages found', function () {
    Http::fake([
        'https://repo.mysql.com/' => Http::response('<html><body>No packages here</body></html>', 200),
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $service->resolveLatestAptConfigVersion();
})->throws(RuntimeException::class, 'Could not find any mysql-apt-config packages');

it('installMysql auto-resolves apt config version when not pinned', function () {
    Http::fake([
        'https://repo.mysql.com/' => Http::response(
            '<a href="mysql-apt-config_0.8.33-1_all.deb">mysql-apt-config_0.8.33-1_all.deb</a>',
            200
        ),
    ]);

    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')->andReturn([
        'success' => true,
        'output' => 'mysql  Ver 8.4.0 for Linux',
        'exit_code' => 0,
    ]);
    $sshMock->shouldReceive('uploadFile')->never();

    $service = new MysqlProvisionService($sshMock);
    $result = $service->installMysql($node);

    expect($result['apt_config_version'])->toBe('0.8.33-1')
        ->and($result['mysql_installed'])->toBeTrue()
        ->and($result['mysql_shell_installed'])->toBeTrue();
});

it('installMysql uses pinned apt config version', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$commands) {
            $commands[] = ['command' => $command, 'action' => $action];

            return [
                'success' => true,
                'output' => 'mysql  Ver 8.4.0 for Linux',
                'exit_code' => 0,
            ];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->installMysql($node, '0.8.32-1');

    expect($result['apt_config_version'])->toBe('0.8.32-1');

    // Should have downloaded the specific version
    $repoCmd = collect($commands)->first(fn ($c) => $c['action'] === 'provision.mysql_repo');
    expect($repoCmd['command'])->toContain('mysql-apt-config_0.8.32-1_all.deb');
});

it('installMysql uses pinned mysql version for install command', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$commands) {
            $commands[] = ['command' => $command, 'action' => $action];

            return [
                'success' => true,
                'output' => 'mysql  Ver 8.4.0 for Linux',
                'exit_code' => 0,
            ];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->installMysql($node, '0.8.33-1', '8.4.0-1ubuntu24.04');

    // Should have pinned mysql-server version
    $installCmd = collect($commands)->first(fn ($c) => $c['action'] === 'provision.mysql_install');
    expect($installCmd['command'])->toContain('mysql-server=8.4.0-1ubuntu24.04');
});

it('installMysql updates node model after install', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'mysql_installed' => false,
        'mysql_shell_installed' => false,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->andReturn([
            'success' => true,
            'output' => 'mysql  Ver 8.4.0',
            'exit_code' => 0,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $service->installMysql($node, '0.8.33-1');

    $node->refresh();
    expect($node->mysql_installed)->toBeTrue()
        ->and($node->mysql_shell_installed)->toBeTrue();
});

it('installMysql detects official repo from policy output', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $callCount = 0;
    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$callCount) {
            $callCount++;
            if ($action === 'provision.verify_server_source') {
                return ['success' => true, 'output' => "mysql-server:\n  Installed: 8.4.0\n  500 https://repo.mysql.com/apt", 'exit_code' => 0];
            }
            if ($action === 'provision.verify_shell_source') {
                return ['success' => true, 'output' => "mysql-shell:\n  Installed: 8.4.0\n  1001 https://repo.mysql.com/apt", 'exit_code' => 0];
            }

            return ['success' => true, 'output' => 'mysql  Ver 8.4.0', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->installMysql($node, '0.8.33-1');

    expect($result['server_from_official_repo'])->toBeTrue()
        ->and($result['shell_from_official_repo'])->toBeTrue();
});

it('installMysqlRouter installs when repo already present', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$commands) {
            $commands[] = $action;
            if ($action === 'provision.check_router_repo') {
                return ['success' => true, 'output' => 'REPO_OK', 'exit_code' => 0];
            }
            if ($action === 'provision.check_router_version') {
                return ['success' => true, 'output' => 'MySQL Router Ver 8.4.0', 'exit_code' => 0];
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->installMysqlRouter($node);

    expect($result['installed'])->toBeTrue()
        ->and($result['version'])->toContain('8.4.0');

    // Should NOT have called repo setup actions
    expect($commands)->not->toContain('provision.router_prerequisites')
        ->and($commands)->not->toContain('provision.router_gpg_key')
        ->and($commands)->toContain('provision.router_install');
});

it('installMysqlRouter sets up repo when missing', function () {
    Http::fake([
        'https://repo.mysql.com/' => Http::response(
            '<a href="mysql-apt-config_0.8.33-1_all.deb">mysql-apt-config_0.8.33-1_all.deb</a>',
            200
        ),
    ]);

    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$commands) {
            $commands[] = $action;
            if ($action === 'provision.check_router_repo') {
                return ['success' => true, 'output' => 'REPO_MISSING', 'exit_code' => 0];
            }
            if ($action === 'provision.check_router_version') {
                return ['success' => true, 'output' => 'MySQL Router Ver 8.4.0', 'exit_code' => 0];
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->installMysqlRouter($node);

    expect($result['installed'])->toBeTrue();

    // Should have called repo setup actions
    expect($commands)->toContain('provision.router_prerequisites')
        ->and($commands)->toContain('provision.router_gpg_key')
        ->and($commands)->toContain('provision.router_preseed')
        ->and($commands)->toContain('provision.router_add_repo')
        ->and($commands)->toContain('provision.router_install');
});

it('installMysqlRouter uses provided apt config version', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$commands) {
            $commands[] = ['command' => $command, 'action' => $action];
            if ($action === 'provision.check_router_repo') {
                return ['success' => true, 'output' => 'REPO_MISSING', 'exit_code' => 0];
            }
            if ($action === 'provision.check_router_version') {
                return ['success' => true, 'output' => 'MySQL Router Ver 8.4.0', 'exit_code' => 0];
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $service->installMysqlRouter($node, '0.8.32-1');

    $repoCmd = collect($commands)->first(fn ($c) => $c['action'] === 'provision.router_add_repo');
    expect($repoCmd['command'])->toContain('mysql-apt-config_0.8.32-1_all.deb');
});

it('installMysqlRouter updates node model', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'mysql_router_installed' => false,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) {
            if ($action === 'provision.check_router_repo') {
                return ['success' => true, 'output' => 'REPO_OK', 'exit_code' => 0];
            }
            if ($action === 'provision.check_router_version') {
                return ['success' => true, 'output' => 'MySQL Router Ver 8.4.0', 'exit_code' => 0];
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $service->installMysqlRouter($node);

    $node->refresh();
    expect($node->mysql_router_installed)->toBeTrue();
});

it('installMysqlRouter marks not installed when version check fails', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) {
            if ($action === 'provision.check_router_repo') {
                return ['success' => true, 'output' => 'REPO_OK', 'exit_code' => 0];
            }
            if ($action === 'provision.check_router_version') {
                return ['success' => false, 'output' => 'command not found', 'exit_code' => 127];
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->installMysqlRouter($node);

    expect($result['installed'])->toBeFalse();
    $node->refresh();
    expect($node->mysql_router_installed)->toBeFalse();
});

it('writeMysqlConfig generates correct config with mysql_server_id', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $server = Server::factory()->create(['host' => '10.0.0.1']);
    $node = MysqlNode::factory()->primary()->create([
        'server_id' => $server->id,
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
        'mysql_x_port' => 33060,
        'mysql_server_id' => 42,
    ]);

    $uploadedContent = null;
    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('uploadFile')
        ->once()
        ->withArgs(function (MysqlNode $n, string $path, string $content) use (&$uploadedContent) {
            $uploadedContent = $content;

            return $path === '/tmp/innodb-cluster.cnf';
        })
        ->andReturn(true);

    $sshMock->shouldReceive('exec')
        ->andReturn([
            'success' => true,
            'output' => '/etc/mysql/conf.d/',
            'exit_code' => 0,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->writeMysqlConfig($node);

    expect($result['success'])->toBeTrue();
    expect($uploadedContent)->toContain('server-id = 42')
        ->and($uploadedContent)->toContain('report-host = 10.0.0.1')
        ->and($uploadedContent)->toContain('port = 3306')
        ->and($uploadedContent)->toContain('mysqlx-port = 33060')
        ->and($uploadedContent)->toContain('gtid_mode = ON')
        ->and($uploadedContent)->toContain('group_replication.so');
});

it('writeMysqlConfig uses node id when mysql_server_id is null', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_server_id' => null,
    ]);

    $uploadedContent = null;
    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('uploadFile')
        ->once()
        ->withArgs(function (MysqlNode $n, string $path, string $content) use (&$uploadedContent) {
            $uploadedContent = $content;

            return true;
        })
        ->andReturn(true);

    $sshMock->shouldReceive('exec')
        ->andReturn([
            'success' => true,
            'output' => '/etc/mysql/conf.d/',
            'exit_code' => 0,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $service->writeMysqlConfig($node);

    expect($uploadedContent)->toContain("server-id = {$node->id}");
});

it('writeMysqlConfig updates node on success', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'mysql_configured' => false,
        'mysql_server_id' => 99,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('uploadFile')->once()->andReturn(true);
    $sshMock->shouldReceive('exec')
        ->andReturn([
            'success' => true,
            'output' => '/etc/mysql/conf.d/',
            'exit_code' => 0,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $service->writeMysqlConfig($node);

    $node->refresh();
    expect($node->mysql_configured)->toBeTrue()
        ->and($node->mysql_server_id)->toBe(99);
});

it('writeMysqlConfig does not update node on failure', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'mysql_configured' => false,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('uploadFile')->once()->andReturn(true);

    $callCount = 0;
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$callCount) {
            $callCount++;
            if ($action === 'provision.detect_confdir') {
                return ['success' => true, 'output' => '/etc/mysql/conf.d/', 'exit_code' => 0];
            }

            // write_config fails
            return ['success' => false, 'output' => 'Permission denied', 'exit_code' => 1];
        });

    $service = new MysqlProvisionService($sshMock);
    $service->writeMysqlConfig($node);

    $node->refresh();
    expect($node->mysql_configured)->toBeFalse();
});

it('writeMysqlConfig uses fallback conf dir when detection returns empty', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'mysql_server_id' => 1,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('uploadFile')->once()->andReturn(true);

    $commands = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action) use (&$commands) {
            $commands[] = ['command' => $command, 'action' => $action];
            if ($action === 'provision.detect_confdir') {
                return ['success' => true, 'output' => '', 'exit_code' => 0];
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $service->writeMysqlConfig($node);

    // Should use the fallback /etc/mysql/conf.d/ directory
    $writeCmd = collect($commands)->first(fn ($c) => $c['action'] === 'provision.write_config');
    expect($writeCmd['command'])->toContain('/etc/mysql/conf.d/innodb-cluster.cnf');
});

it('restartMysql calls systemctl restart with sudo', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->withArgs(function (MysqlNode $n, string $command, string $action, bool $sudo) use ($node) {
            return $n->id === $node->id
                && str_contains($command, 'systemctl restart mysql')
                && $action === 'provision.restart_mysql'
                && $sudo === true;
        })
        ->andReturn([
            'success' => true,
            'output' => '',
            'exit_code' => 0,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->restartMysql($node);

    expect($result['success'])->toBeTrue();
});

it('restartMysql returns failure when systemctl fails', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => 'Job for mysql.service failed',
            'exit_code' => 1,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->restartMysql($node);

    expect($result['success'])->toBeFalse()
        ->and($result['output'])->toContain('Job for mysql.service failed');
});

it('bootstrapRouter runs bootstrap and starts service on success', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $accessNode = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);
    $primaryServer = Server::factory()->create(['host' => '10.0.0.1']);
    $primaryNode = MysqlNode::factory()->primary()->create([
        'server_id' => $primaryServer->id,
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $actions = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$actions) {
            $actions[] = $action;

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->bootstrapRouter($accessNode, $primaryNode, 'clusterpass');

    expect($result['success'])->toBeTrue();
    expect($actions)->toContain('provision.router_user')
        ->and($actions)->toContain('provision.router_bootstrap')
        ->and($actions)->toContain('provision.router_start');
});

it('bootstrapRouter does not start service on failure', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $accessNode = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);
    $primaryServer = Server::factory()->create(['host' => '10.0.0.1']);
    $primaryNode = MysqlNode::factory()->primary()->create([
        'server_id' => $primaryServer->id,
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $actions = [];
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) use (&$actions) {
            $actions[] = $action;
            if ($action === 'provision.router_bootstrap') {
                return ['success' => false, 'output' => 'ERROR: Unable to connect', 'exit_code' => 1];
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $result = $service->bootstrapRouter($accessNode, $primaryNode, 'clusterpass');

    expect($result['success'])->toBeFalse();
    expect($actions)->not->toContain('provision.router_start');
});

it('bootstrapRouter passes correct bootstrap command with primary host', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $accessNode = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);
    $primaryServer = Server::factory()->create(['host' => '10.0.0.1']);
    $primaryNode = MysqlNode::factory()->primary()->create([
        'server_id' => $primaryServer->id,
        'cluster_id' => $cluster->id,
        'mysql_port' => 3306,
    ]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->andReturnUsing(function (MysqlNode $n, string $command, string $action, bool $sudo = false) {
            if ($action === 'provision.router_bootstrap') {
                expect($command)->toContain('clusteradmin@10.0.0.1:3306')
                    ->and($command)->toContain('--conf-bind-address=0.0.0.0')
                    ->and($command)->toContain('--force');
            }

            return ['success' => true, 'output' => 'ok', 'exit_code' => 0];
        });

    $service = new MysqlProvisionService($sshMock);
    $service->bootstrapRouter($accessNode, $primaryNode, 'clusterpass');
});

it('getRouterStatus handles empty output', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->access()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => '',
            'exit_code' => 4,
        ]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->getRouterStatus($node);

    expect($result['running'])->toBeFalse()
        ->and($result['output'])->toBe('');
});

// --- detectSystemInfo() tests ---

it('detectSystemInfo detects RAM CPU and OS', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')
        ->with($node, Mockery::pattern('/MemTotal/'), 'provision.detect_ram')
        ->once()
        ->andReturn(['success' => true, 'output' => '8192', 'exit_code' => 0]);
    $sshMock->shouldReceive('exec')
        ->with($node, Mockery::pattern('/nproc/'), 'provision.detect_cpu')
        ->once()
        ->andReturn(['success' => true, 'output' => '4', 'exit_code' => 0]);
    $sshMock->shouldReceive('exec')
        ->with($node, Mockery::pattern('/PRETTY_NAME/'), 'provision.detect_os_name')
        ->once()
        ->andReturn(['success' => true, 'output' => 'Ubuntu 22.04.3 LTS', 'exit_code' => 0]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->detectSystemInfo($node);

    expect($result['ram_mb'])->toBe(8192)
        ->and($result['cpu_cores'])->toBe(4)
        ->and($result['os_name'])->toBe('Ubuntu 22.04.3 LTS');

    $node->refresh();
    expect($node->server->ram_mb)->toBe(8192)
        ->and($node->server->cpu_cores)->toBe(4)
        ->and($node->server->os_name)->toBe('Ubuntu 22.04.3 LTS');
});

it('detectSystemInfo handles empty output gracefully', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $sshMock = Mockery::mock(SshService::class);
    $sshMock->shouldReceive('exec')->andReturn(['success' => false, 'output' => '', 'exit_code' => 1]);

    $service = new MysqlProvisionService($sshMock);
    $result = $service->detectSystemInfo($node);

    expect($result['ram_mb'])->toBe(0)
        ->and($result['cpu_cores'])->toBe(0)
        ->and($result['os_name'])->toBe('');
});

// --- calculateTuning() tests ---

it('calculateTuning returns sensible values for 2GB server', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $tuning = $service->calculateTuning(2048, 2);

    expect($tuning['innodb_buffer_pool_size'])->toBe('1228M')
        ->and($tuning['innodb_buffer_pool_instances'])->toBe(1)
        ->and($tuning['max_connections'])->toBe(100)
        ->and($tuning['replica_parallel_workers'])->toBe(2);
});

it('calculateTuning scales up for 32GB server', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $tuning = $service->calculateTuning(32768, 8);

    expect($tuning['innodb_buffer_pool_instances'])->toBeGreaterThanOrEqual(8)
        ->and($tuning['max_connections'])->toBe(500)
        ->and($tuning['replica_parallel_workers'])->toBe(8);

    // Buffer pool should be ~60% of 32GB = ~19660MB
    expect($tuning['innodb_buffer_pool_size'])->toContain('M');
});

it('calculateTuning handles minimum values for 512MB server', function () {
    $sshMock = Mockery::mock(SshService::class);
    $service = new MysqlProvisionService($sshMock);

    $tuning = $service->calculateTuning(512, 1);

    expect($tuning['max_connections'])->toBe(50)
        ->and($tuning['innodb_buffer_pool_instances'])->toBe(1)
        ->and($tuning['replica_parallel_workers'])->toBe(2);
});

it('writeMysqlConfig includes tuning section when RAM is known', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $server = Server::factory()->create([
        'ram_mb' => 8192,
        'cpu_cores' => 4,
    ]);
    $node = MysqlNode::factory()->primary()->create([
        'server_id' => $server->id,
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    // Capture the config content uploaded via SFTP
    $uploadedContent = '';
    $sshMock->shouldReceive('uploadFile')
        ->once()
        ->withArgs(function ($n, $path, $content) use (&$uploadedContent) {
            $uploadedContent = $content;

            return $path === '/tmp/innodb-cluster.cnf';
        })
        ->andReturn(true);
    $sshMock->shouldReceive('exec')
        ->with($node, Mockery::pattern('/includedir/'), 'provision.detect_confdir')
        ->andReturn(['success' => true, 'output' => '/etc/mysql/conf.d/', 'exit_code' => 0]);
    $sshMock->shouldReceive('exec')
        ->with($node, Mockery::pattern('/mkdir/'), 'provision.write_config', Mockery::any())
        ->andReturn(['success' => true, 'output' => '', 'exit_code' => 0]);

    $service = new MysqlProvisionService($sshMock);
    $service->writeMysqlConfig($node);

    // Verify tuning section is present
    expect($uploadedContent)->toContain('Performance tuning')
        ->and($uploadedContent)->toContain('innodb_buffer_pool_size')
        ->and($uploadedContent)->toContain('max_connections')
        ->and($uploadedContent)->toContain('8192MB RAM');
});

it('writeMysqlConfig omits tuning section when RAM is unknown', function () {
    $cluster = MysqlCluster::factory()->online()->create();
    $server = Server::factory()->create([
        'ram_mb' => null,
        'cpu_cores' => null,
    ]);
    $node = MysqlNode::factory()->primary()->create([
        'server_id' => $server->id,
        'cluster_id' => $cluster->id,
    ]);

    $sshMock = Mockery::mock(SshService::class);

    $uploadedContent = '';
    $sshMock->shouldReceive('uploadFile')
        ->once()
        ->withArgs(function ($n, $path, $content) use (&$uploadedContent) {
            $uploadedContent = $content;

            return true;
        })
        ->andReturn(true);
    $sshMock->shouldReceive('exec')->andReturn(['success' => true, 'output' => '/etc/mysql/conf.d/', 'exit_code' => 0]);

    $service = new MysqlProvisionService($sshMock);
    $service->writeMysqlConfig($node);

    expect($uploadedContent)->not->toContain('Performance tuning')
        ->and($uploadedContent)->not->toContain('innodb_buffer_pool_size');
});
