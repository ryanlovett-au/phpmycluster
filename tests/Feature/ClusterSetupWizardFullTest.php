<?php

use App\Jobs\ProvisionClusterJob;
use App\Livewire\ClusterSetupWizard;
use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// ─── mount() ────────────────────────────────────────────────────────────────

it('starts at step 1 for a new cluster', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->assertSet('step', 1)
        ->assertSet('isReprovision', false)
        ->assertSet('clusterName', '')
        ->assertSet('communicationStack', 'MYSQL');
});

it('renders the step indicators', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->assertSee('Cluster Details')
        ->assertSee('Primary Node')
        ->assertSee('SSH Key')
        ->assertSee('Provision');
});

it('renders the create cluster heading', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->assertSee('Create InnoDB Cluster');
});

// ─── mount() with existing cluster (reprovision) ───────────────────────────

it('loads existing cluster data in reprovision mode', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'existing-cluster',
        'communication_stack' => 'MYSQL',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'name' => 'node-1',
        'host' => '10.0.0.1',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'ssh_private_key_encrypted' => '', // empty key = sshKeyMissing
    ]);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->assertSet('isReprovision', true)
        ->assertSet('clusterId', $cluster->id)
        ->assertSet('clusterName', 'existing-cluster')
        ->assertSet('seedHost', '10.0.0.1')
        ->assertSet('sshKeyMissing', true)
        ->assertSet('step', 3); // starts at step 3 when SSH key needs setup
});

it('skips to step 4 when SSH key works in reprovision mode', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'working-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'ssh_private_key_encrypted' => 'valid-key-content',
    ]);

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->andReturn(['success' => true, 'hostname' => 'node-1', 'os' => 'Ubuntu 22.04']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->assertSet('isReprovision', true)
        ->assertSet('step', 4)
        ->assertSet('sshKeyMode', 'existing');
});

it('sets sshKeyAuthFailed when SSH key auth fails in reprovision', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'auth-fail-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.2',
        'ssh_private_key_encrypted' => 'bad-key-content',
    ]);

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->andReturn(['success' => false, 'error' => 'Authentication failed']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->assertSet('isReprovision', true)
        ->assertSet('sshKeyAuthFailed', true)
        ->assertSet('sshKeyMode', 'generate')
        ->assertSet('step', 3);
});

it('handles SSH test exception during reprovision mount', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'exception-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.3',
        'ssh_private_key_encrypted' => 'some-key',
    ]);

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnection')
        ->once()
        ->andThrow(new RuntimeException('Connection timed out'));
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->assertSet('isReprovision', true)
        ->assertSet('sshKeyAuthFailed', true)
        ->assertSet('step', 3);
});

// ─── nextStep() / previousStep() ───────────────────────────────────────────

it('advances from step 1 to step 2 with valid data', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'my-new-cluster')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->call('nextStep')
        ->assertSet('step', 2)
        ->assertHasNoErrors();
});

it('fails step 1 validation without cluster name', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', '')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->call('nextStep')
        ->assertHasErrors(['clusterName' => 'required'])
        ->assertSet('step', 1);
});

it('fails step 1 validation without admin password', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'my-new-cluster')
        ->set('clusterAdminPassword', '')
        ->call('nextStep')
        ->assertHasErrors(['clusterAdminPassword' => 'required'])
        ->assertSet('step', 1);
});

it('fails step 1 validation with short admin password', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'my-new-cluster')
        ->set('clusterAdminPassword', 'short')
        ->call('nextStep')
        ->assertHasErrors(['clusterAdminPassword' => 'min'])
        ->assertSet('step', 1);
});

it('fails step 1 validation with duplicate cluster name', function () {
    $user = createAdmin();
    MysqlCluster::factory()->create(['name' => 'taken-name']);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'taken-name')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->call('nextStep')
        ->assertHasErrors(['clusterName' => 'unique'])
        ->assertSet('step', 1);
});

it('advances from step 2 to step 3 with valid seed host', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('step', 2)
        ->set('seedHost', '10.0.0.1')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->assertHasNoErrors();
});

it('fails step 2 validation without seed host', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('step', 2)
        ->set('seedHost', '')
        ->call('nextStep')
        ->assertHasErrors(['seedHost' => 'required'])
        ->assertSet('step', 2);
});

it('advances from step 3 to step 4', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('step', 3)
        ->call('nextStep')
        ->assertSet('step', 4);
});

it('goes back from step 2 to step 1', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('step', 2)
        ->call('previousStep')
        ->assertSet('step', 1);
});

it('does not go below step 1', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->assertSet('step', 1)
        ->call('previousStep')
        ->assertSet('step', 1);
});

it('goes back from step 3 to step 2', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('step', 3)
        ->call('previousStep')
        ->assertSet('step', 2);
});

// ─── generateSshKey() ──────────────────────────────────────────────────────

it('generates an SSH key pair', function () {
    $user = createAdmin();
    $keyPair = ['private' => 'test-private-key', 'public' => 'ssh-ed25519 AAAA testkey'];

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('generateKeyPair')->once()->andReturn($keyPair);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->call('generateSshKey')
        ->assertSet('generatedKeyPair', $keyPair);
});

// ─── testSshConnection() ───────────────────────────────────────────────────

it('tests SSH connection successfully', function () {
    $user = createAdmin();

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnectionDirect')
        ->once()
        ->andReturn(['success' => true, 'hostname' => 'myhost', 'os' => 'Ubuntu 22.04']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('seedHost', '10.0.0.1')
        ->set('seedSshPort', 22)
        ->set('seedSshUser', 'root')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'my-key')
        ->call('testSshConnection');

    // The provisionSteps should have a success entry
});

it('shows error when SSH connection test fails', function () {
    $user = createAdmin();

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnectionDirect')
        ->once()
        ->andReturn(['success' => false, 'error' => 'Connection refused']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('seedHost', '10.0.0.1')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'my-key')
        ->call('testSshConnection');
});

it('shows error when SSH connection test throws exception', function () {
    $user = createAdmin();

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnectionDirect')
        ->once()
        ->andThrow(new RuntimeException('Network unreachable'));
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('seedHost', '10.0.0.1')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'my-key')
        ->call('testSshConnection');
});

it('tests SSH connection with generated key', function () {
    $user = createAdmin();

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnectionDirect')
        ->once()
        ->andReturn(['success' => true, 'hostname' => 'server1', 'os' => 'Ubuntu 24.04']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('seedHost', '10.0.0.1')
        ->set('sshKeyMode', 'generate')
        ->set('generatedKeyPair', ['private' => 'gen-priv', 'public' => 'gen-pub'])
        ->call('testSshConnection');
});

// ─── parseHostField() ──────────────────────────────────────────────────────

it('parses user@host format from seed host field', function () {
    $user = createAdmin();

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnectionDirect')
        ->once()
        ->andReturn(['success' => true, 'hostname' => 'h', 'os' => 'Ubuntu']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('seedHost', 'admin@10.0.0.5')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'key')
        ->call('testSshConnection')
        ->assertSet('seedSshUser', 'admin')
        ->assertSet('seedHost', '10.0.0.5');
});

it('keeps plain host without user@ prefix', function () {
    $user = createAdmin();

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnectionDirect')
        ->once()
        ->andReturn(['success' => true, 'hostname' => 'h', 'os' => 'Ubuntu']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('seedHost', '10.0.0.5')
        ->set('seedSshUser', 'root')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'key')
        ->call('testSshConnection')
        ->assertSet('seedSshUser', 'root')
        ->assertSet('seedHost', '10.0.0.5');
});

// ─── provision() ────────────────────────────────────────────────────────────

it('creates a new cluster and node when provisioning', function () {
    Queue::fake();
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'provision-test')
        ->set('clusterAdminUser', 'clusteradmin')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->set('seedHost', '10.0.0.1')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'my-private-key')
        ->set('mysqlRootPassword', 'rootpass123')
        ->call('provision')
        ->assertSet('provisioning', true);

    Queue::assertPushed(ProvisionClusterJob::class);

    $cluster = MysqlCluster::where('name', 'provision-test')->first();
    expect($cluster)->not->toBeNull();
    expect($cluster->status->value)->toBe('pending');

    $node = $cluster->nodes()->first();
    expect($node)->not->toBeNull();
    expect($node->host)->toBe('10.0.0.1');
});

it('validates mysql root password is required for new clusters', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('mysqlRootPassword', '')
        ->call('provision')
        ->assertHasErrors(['mysqlRootPassword' => 'required']);
});

it('provisions with generated key', function () {
    Queue::fake();
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'gen-key-cluster')
        ->set('clusterAdminUser', 'clusteradmin')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->set('seedHost', '10.0.0.2')
        ->set('sshKeyMode', 'generate')
        ->set('generatedKeyPair', ['private' => 'gen-priv', 'public' => 'gen-pub'])
        ->set('mysqlRootPassword', 'rootpass123')
        ->call('provision')
        ->assertSet('provisioning', true);

    $cluster = MysqlCluster::where('name', 'gen-key-cluster')->first();
    $node = $cluster->nodes()->first();
    expect($node->ssh_public_key)->toBe('gen-pub');
});

it('uses custom seed name when provided', function () {
    Queue::fake();
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'named-node-cluster')
        ->set('clusterAdminUser', 'clusteradmin')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->set('seedHost', '10.0.0.3')
        ->set('seedName', 'my-primary-node')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'key')
        ->set('mysqlRootPassword', 'rootpass123')
        ->call('provision');

    $cluster = MysqlCluster::where('name', 'named-node-cluster')->first();
    $node = $cluster->nodes()->first();
    expect($node->name)->toBe('my-primary-node');
});

// ─── provision() — reprovision mode ────────────────────────────────────────

it('updates existing records when reprovisioning', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create([
        'name' => 'reprov-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_root_password_encrypted' => 'old-root-pass',
    ]);

    // Mock SshService so mount doesn't fail
    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnection')
        ->andReturn(['success' => true, 'hostname' => 'node', 'os' => 'Ubuntu']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->set('seedHost', '10.0.0.2')
        ->set('mysqlRootPassword', 'new-root-pass')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'new-key')
        ->call('provision')
        ->assertSet('provisioning', true);

    Queue::assertPushed(ProvisionClusterJob::class);

    $node->refresh();
    expect($node->host)->toBe('10.0.0.2');
    expect($cluster->fresh()->status->value)->toBe('pending');
});

it('uses stored root password when empty in reprovision', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->online()->create([
        'name' => 'stored-pass-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->primary()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'mysql_root_password_encrypted' => 'stored-pass',
    ]);

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnection')
        ->andReturn(['success' => true, 'hostname' => 'node', 'os' => 'Ubuntu']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->set('mysqlRootPassword', '')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'key')
        ->call('provision')
        ->assertSet('provisioning', true);

    Queue::assertPushed(ProvisionClusterJob::class);
});

it('preserves SSH key if not provided in reprovision', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'preserve-key-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'ssh_private_key_encrypted' => 'original-key',
        'ssh_public_key' => 'original-pub',
    ]);

    $mock = Mockery::mock(SshService::class);
    $mock->shouldReceive('testConnection')
        ->andReturn(['success' => true, 'hostname' => 'node', 'os' => 'Ubuntu']);
    app()->instance(SshService::class, $mock);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', '') // empty = don't overwrite
        ->set('mysqlRootPassword', 'rootpass')
        ->call('provision')
        ->assertSet('provisioning', true);

    $node->refresh();
    // The original key should be preserved since no new key was provided
    expect($node->ssh_private_key_encrypted)->toBe('original-key');
    expect($node->ssh_public_key)->toBe('original-pub');
});

it('uses stored root password for reprovision when empty', function () {
    Queue::fake();
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'no-pass-reprov',
        'cluster_admin_user' => 'clusteradmin',
        'cluster_admin_password_encrypted' => 'testpass',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.1',
        'ssh_private_key_encrypted' => 'key',
        'mysql_root_password_encrypted' => 'stored-root-pass',
    ]);

    $this->mock(SshService::class, function ($mock) {
        $mock->shouldReceive('testConnection')
            ->andReturn(['success' => true, 'hostname' => 'node', 'os' => 'Ubuntu']);
        $mock->shouldReceive('generateKeyPair')
            ->andReturn(['private' => 'priv', 'public' => 'pub']);
    });

    // When SSH key works, reprovision mounts at step 4 with isReprovision=true
    // Provide a root password to pass validation, then check it provisions
    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->assertSet('isReprovision', true)
        ->assertSet('step', 4)
        ->set('mysqlRootPassword', 'stored-root-pass')
        ->call('provision')
        ->assertHasNoErrors()
        ->assertSet('provisioning', true);
});

// ─── pollProgress() ─────────────────────────────────────────────────────────

it('does nothing when no clusterId is set', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->call('pollProgress')
        ->assertSet('provisioning', false);
});

it('updates steps from cache during provisioning', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create();

    $steps = [
        ['message' => 'Installing MySQL...', 'status' => 'running', 'time' => '12:00:00'],
    ];
    Cache::put(ProvisionClusterJob::progressKey($cluster->id), [
        'steps' => $steps,
        'status' => 'running',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterId', $cluster->id)
        ->set('provisioning', true)
        ->call('pollProgress')
        ->assertSet('provisionSteps', $steps)
        ->assertSet('provisioning', true);
});

it('marks provisioning complete when cache says complete', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create();

    Cache::put(ProvisionClusterJob::progressKey($cluster->id), [
        'steps' => [['message' => 'Done!', 'status' => 'success', 'time' => '12:01:00']],
        'status' => 'complete',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterId', $cluster->id)
        ->set('provisioning', true)
        ->call('pollProgress')
        ->assertSet('provisioningComplete', true)
        ->assertSet('provisioning', false);
});

it('stops provisioning when cache says failed', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create();

    Cache::put(ProvisionClusterJob::progressKey($cluster->id), [
        'steps' => [['message' => 'Error occurred', 'status' => 'error', 'time' => '12:01:00']],
        'status' => 'failed',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterId', $cluster->id)
        ->set('provisioning', true)
        ->call('pollProgress')
        ->assertSet('provisioning', false)
        ->assertSet('provisioningComplete', false);
});

it('returns early from pollProgress when no cache exists', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create();

    // No cache entry
    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterId', $cluster->id)
        ->set('provisioning', true)
        ->call('pollProgress')
        ->assertSet('provisioning', true); // stays true, no cache to update from
});

// ─── Default property values ────────────────────────────────────────────────

it('has correct default property values', function () {
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->assertSet('sshKeyMode', 'generate')
        ->assertSet('seedSshPort', 22)
        ->assertSet('seedSshUser', 'root')
        ->assertSet('seedMysqlPort', 3306)
        ->assertSet('provisioning', false)
        ->assertSet('provisioningComplete', false)
        ->assertSet('isReprovision', false)
        ->assertSet('publicKeyCopied', false);
});

// ─── Reprovision unique name validation ─────────────────────────────────────

it('allows the same cluster name in reprovision mode', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'existing-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'ssh_private_key_encrypted' => '',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->set('step', 1)
        ->set('clusterName', 'existing-cluster')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->call('nextStep')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

// ─── Reprovision loads mysql root password from node ────────────────────────

it('loads mysql root password from node in reprovision mode', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'load-pass-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'ssh_private_key_encrypted' => '',
        'mysql_root_password_encrypted' => 'stored-mysql-root',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->assertSet('mysqlRootPassword', 'stored-mysql-root');
});

// ─── Reprovision loads SSH and mysql port settings ──────────────────────────

it('loads SSH and mysql port settings from existing node', function () {
    $user = createAdmin();
    $cluster = MysqlCluster::factory()->create([
        'name' => 'ports-cluster',
        'cluster_admin_user' => 'clusteradmin',
    ]);
    $node = MysqlNode::factory()->create([
        'cluster_id' => $cluster->id,
        'host' => '10.0.0.99',
        'ssh_port' => 2222,
        'ssh_user' => 'deploy',
        'mysql_port' => 3307,
        'ssh_private_key_encrypted' => '',
    ]);

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class, ['cluster' => $cluster])
        ->assertSet('seedSshPort', 2222)
        ->assertSet('seedSshUser', 'deploy')
        ->assertSet('seedMysqlPort', 3307)
        ->assertSet('seedHost', '10.0.0.99');
});

// ─── Provision sets initial cache progress ──────────────────────────────────

it('sets initial cache progress when provisioning', function () {
    Queue::fake();
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'cache-test-cluster')
        ->set('clusterAdminUser', 'clusteradmin')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->set('seedHost', '10.0.0.1')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'my-key')
        ->set('mysqlRootPassword', 'rootpass123')
        ->call('provision');

    $cluster = MysqlCluster::where('name', 'cache-test-cluster')->first();
    $progress = Cache::get(ProvisionClusterJob::progressKey($cluster->id));
    expect($progress)->not->toBeNull();
    expect($progress['status'])->toBe('running');
    expect($progress['steps'])->toHaveCount(1);
});

// ─── Communication stack and admin user settings ────────────────────────────

it('saves communication stack setting when provisioning', function () {
    Queue::fake();
    $user = createAdmin();

    Livewire::actingAs($user)
        ->test(ClusterSetupWizard::class)
        ->set('clusterName', 'xcom-cluster')
        ->set('communicationStack', 'XCOM')
        ->set('clusterAdminUser', 'myadmin')
        ->set('clusterAdminPassword', 'supersecurepassword123')
        ->set('seedHost', '10.0.0.1')
        ->set('sshKeyMode', 'existing')
        ->set('existingPrivateKey', 'key')
        ->set('mysqlRootPassword', 'rootpass')
        ->call('provision');

    $cluster = MysqlCluster::where('name', 'xcom-cluster')->first();
    expect($cluster->communication_stack)->toBe('XCOM');
    expect($cluster->cluster_admin_user)->toBe('myadmin');
});
