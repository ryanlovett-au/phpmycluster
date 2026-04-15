<?php

namespace App\Jobs\Concerns;

use App\Models\MysqlCluster;
use App\Models\MysqlNode;
use App\Services\MysqlProvisionService;
use App\Services\MysqlShellService;
use App\Services\SshService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared provisioning logic for both primary node (ProvisionClusterJob)
 * and secondary node (AddNodeJob) provisioning.
 *
 * Jobs using this trait must implement:
 * - getCacheKey(): string — returns the cache key for progress tracking
 * - getRootPassword(Cluster, Node): string — returns the root password for configureInstance
 * - afterProvision(Cluster, Node, array $state, services...): void — cluster-specific steps after node is provisioned
 */
trait ProvisionesNode
{
    /**
     * Run the shared provisioning steps: detect state, install MySQL,
     * write config, restart, and configure instance.
     *
     * Returns the detected host state array on success.
     *
     * @throws \RuntimeException on any failure
     */
    protected function provisionNode(
        MysqlCluster $cluster,
        MysqlNode $node,
        MysqlProvisionService $provisionService,
        MysqlShellService $mysqlShell,
        SshService $sshService,
    ): array {
        // Step 1: Detect host state
        $this->addStep('Probing host state...');
        $state = $this->detectHostState($sshService, $node, $cluster);
        $this->addStep('Host state detected.', 'success');

        // Step 2: Detect OS
        if (! empty($state['os'])) {
            $this->addStep("OS: {$state['os']}", 'success');
        } else {
            $this->addStep('Detecting operating system...');
            $os = $provisionService->detectOs($node);
            $osName = $os['pretty_name'] ?? null;
            if ($osName) {
                $this->addStep("OS: {$osName}", 'success');
            } else {
                $this->addStep('OS: could not be detected — is this a supported Debian/Ubuntu server?', 'error');
                throw new \RuntimeException('Unable to detect OS. Provisioning requires Debian or Ubuntu.');
            }
        }

        // Step 3: Install MySQL (skip if already installed)
        if ($state['mysql_installed'] && $state['shell_installed']) {
            $this->verifyOfficialRepo($state);
            $this->addStep("MySQL already installed ({$state['mysql_version']}), skipping.", 'success');
            $node->update([
                'mysql_installed' => true,
                'mysql_shell_installed' => true,
                'mysql_version' => $state['mysql_version'],
            ]);
        } else {
            $this->installMysql($cluster, $node, $provisionService);
        }

        // Step 4: Detect system hardware for performance tuning
        $this->addStep('Detecting system hardware...');
        $sysInfo = $provisionService->detectSystemInfo($node);
        $node->refresh();
        if ($sysInfo['ram_mb'] > 0) {
            $ramGb = round($sysInfo['ram_mb'] / 1024, 1);
            $this->addStep("{$ramGb}GB RAM, {$sysInfo['cpu_cores']} CPU cores detected.", 'success');
        } else {
            $this->addStep('Could not detect RAM — using default MySQL settings.', 'success');
        }

        // Step 5: Write MySQL config (skip if already present)
        if ($state['config_exists']) {
            $this->addStep('InnoDB Cluster config already present, skipping.', 'success');
        } else {
            $this->addStep('Writing InnoDB Cluster configuration...');
            $configResult = $provisionService->writeMysqlConfig($node);
            if (! $configResult['success']) {
                throw new \RuntimeException('Failed to write MySQL configuration.');
            }
            $this->addStep('MySQL configuration written.', 'success');
        }

        // Step 5: Restart MySQL to ensure config is loaded
        $this->addStep('Restarting MySQL to ensure configuration is loaded...');
        $restartResult = $provisionService->restartMysql($node);
        if (! $restartResult['success']) {
            throw new \RuntimeException('Failed to restart MySQL.');
        }
        sleep(3);
        $this->addStep('MySQL restarted.', 'success');

        // Step 6: Configure instance for InnoDB Cluster (skip if cluster admin user exists)
        if ($state['cluster_admin_exists']) {
            $this->addStep('Cluster admin user already configured, skipping.', 'success');
            $node->update(['mysql_configured' => true]);
        } else {
            $this->addStep('Configuring instance for InnoDB Cluster...');
            $rootPassword = $this->getRootPassword($cluster, $node);
            $configureResult = $mysqlShell->configureInstance(
                $node,
                $rootPassword,
                $cluster->cluster_admin_user,
                $cluster->cluster_admin_password_encrypted,
            );
            if (! $configureResult['success'] || isset($configureResult['data']['error'])) {
                throw new \RuntimeException('Failed to configure instance: '.($configureResult['data']['error'] ?? $configureResult['raw_output']));
            }
            $this->addStep('Instance configured.', 'success');

            $this->addStep('Restarting MySQL after configuration...');
            $provisionService->restartMysql($node);
            sleep(3);
            $this->addStep('MySQL restarted.', 'success');

            $node->update(['mysql_configured' => true]);
        }

        return $state;
    }

    /**
     * Verify that installed MySQL packages are from the official repo.
     */
    protected function verifyOfficialRepo(array $state): void
    {
        if (! $state['mysql_from_official_repo'] || ! $state['shell_from_official_repo']) {
            $wrongPackages = [];
            if (! $state['mysql_from_official_repo']) {
                $wrongPackages[] = 'mysql-server';
            }
            if (! $state['shell_from_official_repo']) {
                $wrongPackages[] = 'mysql-shell';
            }

            throw new \RuntimeException(
                'The following packages are installed from Ubuntu\'s repository instead of the official MySQL repo: '.
                implode(', ', $wrongPackages).'. '.
                'Ubuntu\'s mysql-shell lacks JavaScript support required by MySQL Shell AdminAPI. '.
                'To fix this, SSH into the node and run: '.
                'sudo apt-get remove -y mysql-server mysql-shell && sudo apt-get autoremove -y '.
                '— then retry provisioning.'
            );
        }
    }

    /**
     * Install MySQL from the official repo with version pinning.
     */
    protected function installMysql(MysqlCluster $cluster, MysqlNode $node, MysqlProvisionService $provisionService): void
    {
        $aptConfigVersion = $cluster->mysql_apt_config_version;
        $pinnedMysqlVersion = $cluster->mysql_version;

        if ($aptConfigVersion && $pinnedMysqlVersion) {
            $this->addStep("Installing MySQL (pinned: {$pinnedMysqlVersion}) from official repo...");
        } else {
            $this->addStep('Resolving latest MySQL version from official repo...');
            $aptConfigVersion = $provisionService->resolveLatestAptConfigVersion();
            $this->addStep("Using mysql-apt-config {$aptConfigVersion}.", 'success');
            $this->addStep('Installing MySQL Server and MySQL Shell (this may take a few minutes)...');
        }

        $installResult = $provisionService->installMysql($node, $aptConfigVersion, $pinnedMysqlVersion);

        if (! $installResult['mysql_installed']) {
            throw new \RuntimeException('MySQL installation failed. Check audit logs for details.');
        }

        $this->addStep("MySQL installed: {$installResult['mysql_version']}", 'success');

        // Record the versions on the cluster for future node additions
        if (! $cluster->mysql_version) {
            $cluster->update([
                'mysql_version' => $installResult['mysql_package_version'],
                'mysql_apt_config_version' => $installResult['apt_config_version'],
            ]);
        }

        if (! ($installResult['server_from_official_repo'] ?? true)) {
            $this->addStep('Warning: mysql-server may not be from the official MySQL repo.', 'error');
        }
        if (! ($installResult['shell_from_official_repo'] ?? true)) {
            throw new \RuntimeException('mysql-shell is not from the official MySQL repository. Ubuntu\'s version lacks JavaScript support.');
        }
    }

    /**
     * Probe the remote host to detect what's already provisioned.
     */
    protected function detectHostState(SshService $sshService, MysqlNode $node, MysqlCluster $cluster): array
    {
        $state = [
            'os' => null,
            'mysql_installed' => false,
            'shell_installed' => false,
            'mysql_version' => null,
            'mysql_from_official_repo' => false,
            'shell_from_official_repo' => false,
            'mysql_running' => false,
            'config_exists' => false,
            'cluster_admin_exists' => false,
            'cluster_exists' => false,
        ];

        try {
            $ssh = $sshService->connect($node);

            // Detect OS
            $osOutput = trim($ssh->exec('cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d= -f2 | tr -d \'"\' '));
            if ($osOutput) {
                $state['os'] = $osOutput;
            }

            // Check MySQL installed
            $mysqlVersion = trim($ssh->exec('mysql --version 2>/dev/null'));
            if ($mysqlVersion && ! str_contains($mysqlVersion, 'not found')) {
                $state['mysql_installed'] = true;
                $state['mysql_version'] = $mysqlVersion;

                $serverPolicy = trim($ssh->exec('apt-cache policy mysql-server 2>/dev/null'));
                $state['mysql_from_official_repo'] = str_contains($serverPolicy, 'repo.mysql.com') || str_contains($serverPolicy, '1001');
            }

            // Check MySQL Shell installed
            $shellVersion = trim($ssh->exec('mysqlsh --version 2>/dev/null'));
            if ($shellVersion && ! str_contains($shellVersion, 'not found')) {
                $state['shell_installed'] = true;

                $shellPolicy = trim($ssh->exec('apt-cache policy mysql-shell 2>/dev/null'));
                $state['shell_from_official_repo'] = str_contains($shellPolicy, 'repo.mysql.com') || str_contains($shellPolicy, '1001');
            }

            // Check MySQL running
            $mysqlActive = trim($ssh->exec('systemctl is-active mysql 2>/dev/null'));
            $state['mysql_running'] = $mysqlActive === 'active';

            // Check InnoDB Cluster config file exists
            $configCheck = trim($ssh->exec('(test -f /etc/mysql/conf.d/innodb-cluster.cnf || test -f /etc/mysql/mysql.conf.d/innodb-cluster.cnf) && echo "exists" 2>/dev/null'));
            $state['config_exists'] = $configCheck === 'exists';

            // Check if cluster admin user exists in MySQL (only if MySQL is running)
            if ($state['mysql_running']) {
                $adminUser = $cluster->cluster_admin_user;
                $adminPassword = $cluster->cluster_admin_password_encrypted;
                $adminCheck = trim($ssh->exec(
                    "mysql -u {$adminUser} -p'{$adminPassword}' -e 'SELECT 1' 2>/dev/null && echo 'OK'"
                ));
                $state['cluster_admin_exists'] = str_contains($adminCheck, 'OK');

                // Check if cluster already exists (only if admin user works)
                if ($state['cluster_admin_exists']) {
                    $clusterCheck = trim($ssh->exec(
                        "mysqlsh --no-wizard -u {$adminUser} -p'{$adminPassword}' -h 127.0.0.1 ".
                        "--js -e 'print(dba.getCluster().status().clusterName)' 2>/dev/null"
                    ));
                    $state['cluster_exists'] = ! empty($clusterCheck) && ! str_contains($clusterCheck, 'Error') && ! str_contains($clusterCheck, 'error');
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Host state detection partial failure: {$e->getMessage()}");
        }

        return $state;
    }

    /**
     * Add a progress step to the cache.
     *
     * When a new step is added, any previous "running" steps
     * are automatically marked as "success" — they must have completed
     * if we've moved on to the next step.
     */
    protected function addStep(string $message, string $status = 'running'): void
    {
        $key = $this->getCacheKey();
        $progress = Cache::get($key, ['steps' => [], 'status' => 'running']);

        foreach ($progress['steps'] as &$step) {
            if ($step['status'] === 'running') {
                $step['status'] = 'success';
            }
        }
        unset($step);

        $progress['steps'][] = [
            'message' => $message,
            'status' => $status,
            'time' => now()->format('H:i:s'),
        ];

        Cache::put($key, $progress, now()->addHours(2));
    }

    /**
     * Set the overall provision status and clean up any lingering "running" steps.
     */
    protected function setStatus(string $status): void
    {
        $key = $this->getCacheKey();
        $progress = Cache::get($key, ['steps' => [], 'status' => 'running']);
        $progress['status'] = $status;

        $resolvedStatus = $status === 'complete' ? 'success' : 'error';
        foreach ($progress['steps'] as &$step) {
            if ($step['status'] === 'running') {
                $step['status'] = $resolvedStatus;
            }
        }

        Cache::put($key, $progress, now()->addHours(2));
    }
}
