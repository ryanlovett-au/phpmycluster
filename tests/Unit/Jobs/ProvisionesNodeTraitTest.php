<?php

use App\Jobs\Concerns\ProvisionesNode;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\MysqlProvisionService;
use App\Services\MysqlShellService;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

/**
 * Concrete test double that uses the ProvisionesNode trait.
 */
class TraitTestJob
{
    use ProvisionesNode;

    public string $cacheKey = 'test_trait_progress';

    protected function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    protected function getRootPassword(MysqlCluster $cluster, MysqlNode $node): string
    {
        return 'test-root-password';
    }
}

// --- addStep() tests ---

it('addStep puts data in cache with running status', function () {
    $job = new TraitTestJob;

    $job->cacheKey = 'test_addstep_'.uniqid();

    // Use reflection to call protected method
    $method = new ReflectionMethod($job, 'addStep');
    $method->invoke($job, 'Starting provisioning...');

    $progress = Cache::get($job->cacheKey);

    expect($progress)->not->toBeNull();
    expect($progress['steps'])->toHaveCount(1);
    expect($progress['steps'][0]['message'])->toBe('Starting provisioning...');
    expect($progress['steps'][0]['status'])->toBe('running');
    expect($progress['status'])->toBe('running');
});

it('addStep marks previous running steps as success when adding new step', function () {
    $job = new TraitTestJob;
    $job->cacheKey = 'test_addstep_auto_'.uniqid();

    $method = new ReflectionMethod($job, 'addStep');

    // Add first step (running)
    $method->invoke($job, 'Step 1...');
    // Add second step (should auto-complete step 1)
    $method->invoke($job, 'Step 2...');

    $progress = Cache::get($job->cacheKey);

    expect($progress['steps'])->toHaveCount(2);
    expect($progress['steps'][0]['status'])->toBe('success'); // Auto-resolved
    expect($progress['steps'][1]['status'])->toBe('running');
});

it('addStep accepts explicit status', function () {
    $job = new TraitTestJob;
    $job->cacheKey = 'test_addstep_explicit_'.uniqid();

    $method = new ReflectionMethod($job, 'addStep');
    $method->invoke($job, 'Something succeeded!', 'success');

    $progress = Cache::get($job->cacheKey);

    expect($progress['steps'][0]['status'])->toBe('success');
});

// --- setStatus() tests ---

it('setStatus complete marks all running steps as success', function () {
    $job = new TraitTestJob;
    $job->cacheKey = 'test_setstatus_complete_'.uniqid();

    $addStep = new ReflectionMethod($job, 'addStep');
    $setStatus = new ReflectionMethod($job, 'setStatus');

    $addStep->invoke($job, 'Step 1...');
    $addStep->invoke($job, 'Step 2...');
    $setStatus->invoke($job, 'complete');

    $progress = Cache::get($job->cacheKey);

    expect($progress['status'])->toBe('complete');
    foreach ($progress['steps'] as $step) {
        expect($step['status'])->toBe('success');
    }
});

it('setStatus failed marks all running steps as error', function () {
    $job = new TraitTestJob;
    $job->cacheKey = 'test_setstatus_failed_'.uniqid();

    $addStep = new ReflectionMethod($job, 'addStep');
    $setStatus = new ReflectionMethod($job, 'setStatus');

    $addStep->invoke($job, 'Step 1...');
    $addStep->invoke($job, 'Step 2...');
    $setStatus->invoke($job, 'failed');

    $progress = Cache::get($job->cacheKey);

    expect($progress['status'])->toBe('failed');
    // Step 1 was auto-resolved to success by addStep when step 2 was added
    expect($progress['steps'][0]['status'])->toBe('success');
    // Step 2 was still running, should now be error
    expect($progress['steps'][1]['status'])->toBe('error');
});

// --- verifyOfficialRepo() tests ---

it('verifyOfficialRepo does not throw when all packages from official repo', function () {
    $job = new TraitTestJob;
    $method = new ReflectionMethod($job, 'verifyOfficialRepo');

    $state = [
        'mysql_from_official_repo' => true,
        'shell_from_official_repo' => true,
    ];

    // Should not throw
    $method->invoke($job, $state);
    expect(true)->toBeTrue();
});

it('verifyOfficialRepo throws when mysql not from official repo', function () {
    $job = new TraitTestJob;
    $method = new ReflectionMethod($job, 'verifyOfficialRepo');

    $state = [
        'mysql_from_official_repo' => false,
        'shell_from_official_repo' => true,
    ];

    expect(fn () => $method->invoke($job, $state))->toThrow(RuntimeException::class, 'mysql-server');
});

it('verifyOfficialRepo throws when shell not from official repo', function () {
    $job = new TraitTestJob;
    $method = new ReflectionMethod($job, 'verifyOfficialRepo');

    $state = [
        'mysql_from_official_repo' => true,
        'shell_from_official_repo' => false,
    ];

    expect(fn () => $method->invoke($job, $state))->toThrow(RuntimeException::class, 'mysql-shell');
});

it('verifyOfficialRepo throws when both packages not from official repo', function () {
    $job = new TraitTestJob;
    $method = new ReflectionMethod($job, 'verifyOfficialRepo');

    $state = [
        'mysql_from_official_repo' => false,
        'shell_from_official_repo' => false,
    ];

    expect(fn () => $method->invoke($job, $state))->toThrow(RuntimeException::class, 'mysql-server');
});

// --- detectHostState() tests ---

it('detectHostState detects a fully provisioned host', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = MysqlNode::factory()->primary()->create(['cluster_id' => $cluster->id]);

    $ssh = Mockery::mock(SSH2::class);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('Ubuntu 22.04.3 LTS');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('mysql  Ver 8.4.0 for Linux');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-server/'))
        ->andReturn('Installed: 8.4.0-1ubuntu22.04  repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('mysqlsh  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-shell/'))
        ->andReturn('Installed: 8.4.0-1ubuntu22.04  repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('active');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('exists');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql -u/'))
        ->andReturn('OK');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --no-wizard/'))
        ->andReturn('test-cluster');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->with($node)->andReturn($ssh);

    $job = new TraitTestJob;
    $method = new ReflectionMethod($job, 'detectHostState');
    $state = $method->invoke($job, $sshService, $node, $cluster);

    expect($state['os'])->toBe('Ubuntu 22.04.3 LTS');
    expect($state['mysql_installed'])->toBeTrue();
    expect($state['shell_installed'])->toBeTrue();
    expect($state['mysql_from_official_repo'])->toBeTrue();
    expect($state['shell_from_official_repo'])->toBeTrue();
    expect($state['mysql_running'])->toBeTrue();
    expect($state['config_exists'])->toBeTrue();
    expect($state['cluster_admin_exists'])->toBeTrue();
    expect($state['cluster_exists'])->toBeTrue();
});

it('detectHostState returns defaults for a fresh host', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $ssh = Mockery::mock(SSH2::class);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('Ubuntu 22.04.3 LTS');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('bash: mysql: command not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('bash: mysqlsh: command not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('inactive');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('');

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->with($node)->andReturn($ssh);

    $job = new TraitTestJob;
    $method = new ReflectionMethod($job, 'detectHostState');
    $state = $method->invoke($job, $sshService, $node, $cluster);

    expect($state['os'])->toBe('Ubuntu 22.04.3 LTS');
    expect($state['mysql_installed'])->toBeFalse();
    expect($state['shell_installed'])->toBeFalse();
    expect($state['mysql_running'])->toBeFalse();
    expect($state['config_exists'])->toBeFalse();
    expect($state['cluster_admin_exists'])->toBeFalse();
    expect($state['cluster_exists'])->toBeFalse();
});

it('detectHostState handles connection exception gracefully', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshService = Mockery::mock(SshService::class);
    $sshService->shouldReceive('connect')->once()->andThrow(new RuntimeException('Connection refused'));

    Log::shouldReceive('warning')->once();

    $job = new TraitTestJob;
    $method = new ReflectionMethod($job, 'detectHostState');
    $state = $method->invoke($job, $sshService, $node, $cluster);

    // All fields should be defaults
    expect($state['os'])->toBeNull();
    expect($state['mysql_installed'])->toBeFalse();
    expect($state['shell_installed'])->toBeFalse();
    expect($state['mysql_running'])->toBeFalse();
    expect($state['config_exists'])->toBeFalse();
    expect($state['cluster_admin_exists'])->toBeFalse();
    expect($state['cluster_exists'])->toBeFalse();
});

// --- installMysql() tests ---

it('installMysql succeeds with pinned version', function () {
    $cluster = MysqlCluster::factory()->create([
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('installMysql')
        ->once()
        ->with($node, '0.8.33-1', '8.4.0')
        ->andReturn([
            'mysql_installed' => true,
            'mysql_version' => 'mysql  Ver 8.4.0',
            'mysql_package_version' => '8.4.0',
            'apt_config_version' => '0.8.33-1',
            'server_from_official_repo' => true,
            'shell_from_official_repo' => true,
        ]);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_installmysql_'.uniqid();

    $method = new ReflectionMethod($job, 'installMysql');
    $method->invoke($job, $cluster, $node, $provisionService);

    // Should not throw; verify progress step was added
    $progress = Cache::get($job->cacheKey);
    expect($progress['steps'])->not->toBeEmpty();
});

it('installMysql resolves latest version when not pinned', function () {
    $cluster = MysqlCluster::factory()->create([
        'mysql_version' => null,
        'mysql_apt_config_version' => null,
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('resolveLatestAptConfigVersion')
        ->once()
        ->andReturn('0.8.33-1');
    $provisionService->shouldReceive('installMysql')
        ->once()
        ->with($node, '0.8.33-1', null)
        ->andReturn([
            'mysql_installed' => true,
            'mysql_version' => 'mysql  Ver 8.4.0',
            'mysql_package_version' => '8.4.0',
            'apt_config_version' => '0.8.33-1',
            'server_from_official_repo' => true,
            'shell_from_official_repo' => true,
        ]);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_installmysql_resolve_'.uniqid();

    $method = new ReflectionMethod($job, 'installMysql');
    $method->invoke($job, $cluster, $node, $provisionService);

    $cluster->refresh();
    expect($cluster->mysql_version)->toBe('8.4.0');
    expect($cluster->mysql_apt_config_version)->toBe('0.8.33-1');
});

it('installMysql throws when mysql_installed is false', function () {
    $cluster = MysqlCluster::factory()->create([
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('installMysql')
        ->once()
        ->andReturn([
            'mysql_installed' => false,
        ]);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_installmysql_fail_'.uniqid();

    $method = new ReflectionMethod($job, 'installMysql');

    expect(fn () => $method->invoke($job, $cluster, $node, $provisionService))
        ->toThrow(RuntimeException::class, 'MySQL installation failed');
});

it('installMysql throws when shell not from official repo', function () {
    $cluster = MysqlCluster::factory()->create([
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('installMysql')
        ->once()
        ->andReturn([
            'mysql_installed' => true,
            'mysql_version' => 'mysql  Ver 8.4.0',
            'mysql_package_version' => '8.4.0',
            'apt_config_version' => '0.8.33-1',
            'server_from_official_repo' => true,
            'shell_from_official_repo' => false,
        ]);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_installmysql_shell_'.uniqid();

    $method = new ReflectionMethod($job, 'installMysql');

    expect(fn () => $method->invoke($job, $cluster, $node, $provisionService))
        ->toThrow(RuntimeException::class, 'mysql-shell is not from the official MySQL repository');
});

// --- provisionNode() full integration tests ---

it('provisionNode succeeds when everything already installed', function () {
    $cluster = MysqlCluster::factory()->online()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $fullyProvisionedState = [
        'os' => 'Ubuntu 22.04',
        'mysql_installed' => true,
        'shell_installed' => true,
        'mysql_version' => 'mysql  Ver 8.4.0',
        'mysql_from_official_repo' => true,
        'shell_from_official_repo' => true,
        'mysql_running' => true,
        'config_exists' => true,
        'cluster_admin_exists' => true,
        'cluster_exists' => false,
    ];

    $sshService = Mockery::mock(SshService::class);
    $ssh = Mockery::mock(SSH2::class);

    // detectHostState mocking
    $sshService->shouldReceive('connect')->once()->andReturn($ssh);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('Ubuntu 22.04');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('mysql  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-server/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('mysqlsh  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-shell/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('active');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('exists');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql -u/'))
        ->andReturn('OK');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --no-wizard/'))
        ->andReturn('');

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('detectSystemInfo')->once()->andReturn(['ram_mb' => 4096, 'cpu_cores' => 2, 'os_name' => 'Ubuntu 22.04']);
    $provisionService->shouldReceive('restartMysql')->once()->andReturn(['success' => true]);

    $mysqlShell = Mockery::mock(MysqlShellService::class);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_provision_full_'.uniqid();

    $method = new ReflectionMethod($job, 'provisionNode');
    $state = $method->invoke($job, $cluster, $node, $provisionService, $mysqlShell, $sshService);

    expect($state['os'])->toBe('Ubuntu 22.04');
    expect($state['mysql_installed'])->toBeTrue();
    expect($state['config_exists'])->toBeTrue();
    expect($state['cluster_admin_exists'])->toBeTrue();

    $node->refresh();
    expect($node->mysql_installed)->toBeTrue();
    expect($node->mysql_shell_installed)->toBeTrue();
    expect($node->mysql_configured)->toBeTrue();
});

it('provisionNode runs full install path for fresh host', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshService = Mockery::mock(SshService::class);
    $ssh = Mockery::mock(SSH2::class);

    // detectHostState — fresh host
    $sshService->shouldReceive('connect')->once()->andReturn($ssh);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('Ubuntu 22.04');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('inactive');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('');

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('detectSystemInfo')->once()->andReturn(['ram_mb' => 8192, 'cpu_cores' => 4, 'os_name' => 'Ubuntu 22.04']);
    $provisionService->shouldReceive('installMysql')->once()->andReturn([
        'mysql_installed' => true,
        'mysql_version' => 'mysql  Ver 8.4.0',
        'mysql_package_version' => '8.4.0',
        'apt_config_version' => '0.8.33-1',
        'server_from_official_repo' => true,
        'shell_from_official_repo' => true,
    ]);
    $provisionService->shouldReceive('writeMysqlConfig')->once()->andReturn(['success' => true]);
    $provisionService->shouldReceive('restartMysql')->twice()->andReturn(['success' => true]);

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('configureInstance')->once()->andReturn([
        'success' => true,
        'data' => [],
        'raw_output' => '',
    ]);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_provision_fresh_'.uniqid();

    $method = new ReflectionMethod($job, 'provisionNode');
    $state = $method->invoke($job, $cluster, $node, $provisionService, $mysqlShell, $sshService);

    expect($state['os'])->toBe('Ubuntu 22.04');
    expect($state['mysql_installed'])->toBeFalse(); // State reflects initial detection

    $node->refresh();
    expect($node->mysql_configured)->toBeTrue();
});

it('provisionNode throws when OS cannot be detected', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshService = Mockery::mock(SshService::class);
    $ssh = Mockery::mock(SSH2::class);

    $sshService->shouldReceive('connect')->once()->andReturn($ssh);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('inactive');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('');

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('detectOs')->once()->andReturn([
        'pretty_name' => null,
    ]);

    $mysqlShell = Mockery::mock(MysqlShellService::class);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_provision_no_os_'.uniqid();

    $method = new ReflectionMethod($job, 'provisionNode');

    expect(fn () => $method->invoke($job, $cluster, $node, $provisionService, $mysqlShell, $sshService))
        ->toThrow(RuntimeException::class, 'Unable to detect OS');
});

it('provisionNode uses detectOs fallback when state has no os', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshService = Mockery::mock(SshService::class);
    $ssh = Mockery::mock(SSH2::class);

    $sshService->shouldReceive('connect')->once()->andReturn($ssh);
    // OS detection via SSH returns empty
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('mysql  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-server/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('mysqlsh  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-shell/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('active');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('exists');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql -u/'))
        ->andReturn('OK');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --no-wizard/'))
        ->andReturn('');

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('detectOs')->once()->andReturn([
        'pretty_name' => 'Debian 12',
    ]);
    $provisionService->shouldReceive('detectSystemInfo')->once()->andReturn(['ram_mb' => 4096, 'cpu_cores' => 2, 'os_name' => 'Debian 12']);
    $provisionService->shouldReceive('restartMysql')->once()->andReturn(['success' => true]);

    $mysqlShell = Mockery::mock(MysqlShellService::class);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_provision_fallback_os_'.uniqid();

    $method = new ReflectionMethod($job, 'provisionNode');
    $state = $method->invoke($job, $cluster, $node, $provisionService, $mysqlShell, $sshService);

    // Should have continued successfully after detectOs fallback
    $node->refresh();
    expect($node->mysql_installed)->toBeTrue();
    expect($node->mysql_configured)->toBeTrue();
});

it('provisionNode throws when writeMysqlConfig fails', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshService = Mockery::mock(SshService::class);
    $ssh = Mockery::mock(SSH2::class);

    $sshService->shouldReceive('connect')->once()->andReturn($ssh);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('Ubuntu 22.04');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('mysql  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-server/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('mysqlsh  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-shell/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('active');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('');

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('detectSystemInfo')->once()->andReturn(['ram_mb' => 4096, 'cpu_cores' => 2, 'os_name' => 'Ubuntu 22.04']);
    $provisionService->shouldReceive('writeMysqlConfig')->once()->andReturn(['success' => false]);

    $mysqlShell = Mockery::mock(MysqlShellService::class);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_provision_config_fail_'.uniqid();

    $method = new ReflectionMethod($job, 'provisionNode');

    expect(fn () => $method->invoke($job, $cluster, $node, $provisionService, $mysqlShell, $sshService))
        ->toThrow(RuntimeException::class, 'Failed to write MySQL configuration');
});

it('provisionNode throws when restartMysql fails', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshService = Mockery::mock(SshService::class);
    $ssh = Mockery::mock(SSH2::class);

    $sshService->shouldReceive('connect')->once()->andReturn($ssh);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('Ubuntu 22.04');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('mysql  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-server/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('mysqlsh  Ver 8.4.0');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/apt-cache policy mysql-shell/'))
        ->andReturn('repo.mysql.com');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('active');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('exists');

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('detectSystemInfo')->once()->andReturn(['ram_mb' => 4096, 'cpu_cores' => 2, 'os_name' => 'Ubuntu 22.04']);
    $provisionService->shouldReceive('restartMysql')->once()->andReturn(['success' => false]);

    $mysqlShell = Mockery::mock(MysqlShellService::class);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_provision_restart_fail_'.uniqid();

    $method = new ReflectionMethod($job, 'provisionNode');

    expect(fn () => $method->invoke($job, $cluster, $node, $provisionService, $mysqlShell, $sshService))
        ->toThrow(RuntimeException::class, 'Failed to restart MySQL');
});

it('provisionNode throws when configureInstance fails', function () {
    $cluster = MysqlCluster::factory()->create([
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
        'mysql_version' => '8.4.0',
        'mysql_apt_config_version' => '0.8.33-1',
    ]);
    $node = MysqlNode::factory()->create(['cluster_id' => $cluster->id]);

    $sshService = Mockery::mock(SshService::class);
    $ssh = Mockery::mock(SSH2::class);

    $sshService->shouldReceive('connect')->once()->andReturn($ssh);
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/os-release/'))
        ->andReturn('Ubuntu 22.04');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysql --version/'))
        ->andReturn('not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/mysqlsh --version/'))
        ->andReturn('not found');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/systemctl is-active/'))
        ->andReturn('inactive');
    $ssh->shouldReceive('exec')
        ->with(Mockery::pattern('/test -f/'))
        ->andReturn('');

    $provisionService = Mockery::mock(MysqlProvisionService::class);
    $provisionService->shouldReceive('detectSystemInfo')->once()->andReturn(['ram_mb' => 4096, 'cpu_cores' => 2, 'os_name' => 'Ubuntu 22.04']);
    $provisionService->shouldReceive('installMysql')->once()->andReturn([
        'mysql_installed' => true,
        'mysql_version' => 'mysql  Ver 8.4.0',
        'mysql_package_version' => '8.4.0',
        'apt_config_version' => '0.8.33-1',
        'server_from_official_repo' => true,
        'shell_from_official_repo' => true,
    ]);
    $provisionService->shouldReceive('writeMysqlConfig')->once()->andReturn(['success' => true]);
    $provisionService->shouldReceive('restartMysql')->once()->andReturn(['success' => true]);

    $mysqlShell = Mockery::mock(MysqlShellService::class);
    $mysqlShell->shouldReceive('configureInstance')->once()->andReturn([
        'success' => false,
        'data' => ['error' => 'Access denied for user root'],
        'raw_output' => 'Access denied for user root',
    ]);

    $job = new TraitTestJob;
    $job->cacheKey = 'test_provision_configure_fail_'.uniqid();

    $method = new ReflectionMethod($job, 'provisionNode');

    expect(fn () => $method->invoke($job, $cluster, $node, $provisionService, $mysqlShell, $sshService))
        ->toThrow(RuntimeException::class, 'Failed to configure instance');
});
