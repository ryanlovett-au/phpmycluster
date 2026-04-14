<?php

namespace App\Services;

use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles provisioning a fresh Debian/Ubuntu node with MySQL 8.4,
 * MySQL Shell, and MySQL Router from the official MySQL APT repository.
 */
class NodeProvisionService
{
    /**
     * Base URL for the MySQL repo directory listing.
     */
    protected const REPO_INDEX_URL = 'https://repo.mysql.com/';

    /**
     * Direct download URL pattern for mysql-apt-config debs.
     */
    protected const APT_CONFIG_URL = 'https://repo.mysql.com/mysql-apt-config_{version}_all.deb';

    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Detect the OS on the node.
     */
    public function detectOs(Node $node): array
    {
        $result = $this->ssh->exec($node, 'cat /etc/os-release 2>/dev/null', 'provision.detect_os');

        $os = [];
        foreach (explode("\n", $result['output']) as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $os[strtolower(trim($key))] = trim($value, '"\'');
            }
        }

        return $os;
    }

    /**
     * Resolve the latest mysql-apt-config version from the MySQL repo directory listing.
     *
     * Fetches https://repo.mysql.com/ and parses the HTML for the latest
     * mysql-apt-config_*_all.deb filename.
     */
    public function resolveLatestAptConfigVersion(): string
    {
        $response = Http::timeout(15)->get(self::REPO_INDEX_URL);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch MySQL repo index from '.self::REPO_INDEX_URL);
        }

        $html = $response->body();

        // Find all mysql-apt-config deb filenames in the directory listing
        preg_match_all('/mysql-apt-config_([\d.]+(?:-\d+)?)_all\.deb/', $html, $matches);

        if (empty($matches[1])) {
            throw new \RuntimeException('Could not find any mysql-apt-config packages at '.self::REPO_INDEX_URL);
        }

        // Sort by version descending and return the latest
        $versions = $matches[1];
        usort($versions, fn ($a, $b) => version_compare(
            str_replace('-', '.', $b),
            str_replace('-', '.', $a)
        ));

        Log::info('Resolved latest mysql-apt-config version: '.$versions[0]);

        return $versions[0];
    }

    /**
     * Get the download URL for a specific mysql-apt-config version.
     */
    public function getAptConfigUrl(string $version): string
    {
        return str_replace('{version}', $version, self::APT_CONFIG_URL);
    }

    /**
     * Install MySQL Server and MySQL Shell from the official MySQL APT repository.
     *
     * For new clusters, pass $aptConfigVersion = null to auto-resolve the latest.
     * For existing clusters (adding nodes), pass the cluster's pinned version
     * and $pinnedMysqlVersion to ensure all nodes run the same version.
     *
     * Ubuntu ships its own mysql-shell package which lacks JavaScript support.
     * We explicitly remove any Ubuntu-packaged versions first and pin the
     * official MySQL repo at higher priority so apt always prefers it.
     */
    public function installMysql(Node $node, ?string $aptConfigVersion = null, ?string $pinnedMysqlVersion = null): array
    {
        $steps = [];

        // Resolve the apt-config version if not pinned
        if (! $aptConfigVersion) {
            $aptConfigVersion = $this->resolveLatestAptConfigVersion();
        }
        $aptConfigUrl = $this->getAptConfigUrl($aptConfigVersion);

        // Install prerequisites
        $steps[] = $this->ssh->exec(
            $node,
            'apt-get update -qq && apt-get install -y -qq wget gnupg lsb-release 2>&1',
            'provision.prerequisites',
            sudo: true,
            timeout: 120
        );

        // Remove any Ubuntu-packaged mysql-shell to avoid conflicts
        $steps[] = $this->ssh->exec(
            $node,
            'dpkg -l mysql-shell 2>/dev/null | grep -q "^ii" && apt-get remove -y -qq mysql-shell 2>&1 || true',
            'provision.remove_distro_shell',
            sudo: true,
            timeout: 120
        );

        // Import the latest MySQL APT repo signing key from Ubuntu keyserver (avoids EXPKEYSIG errors)
        $steps[] = $this->ssh->exec(
            $node,
            'gpg --homedir /tmp --keyserver keyserver.ubuntu.com --recv-keys B7B3B788A8D3785C 2>&1 && '.
            'gpg --homedir /tmp --export B7B3B788A8D3785C | tee /usr/share/keyrings/mysql-archive-keyring.gpg > /dev/null 2>&1 && '.
            'gpg --homedir /tmp --export B7B3B788A8D3785C | apt-key add - 2>&1',
            'provision.mysql_gpg_key',
            sudo: true,
            timeout: 60
        );

        // Pre-seed debconf to avoid interactive prompts from mysql-apt-config
        $steps[] = $this->ssh->exec(
            $node,
            'echo "mysql-apt-config mysql-apt-config/select-server select mysql-8.4-lts" | debconf-set-selections && '.
            'echo "mysql-apt-config mysql-apt-config/select-tools select Enabled" | debconf-set-selections && '.
            'echo "mysql-apt-config mysql-apt-config/select-preview select Disabled" | debconf-set-selections && '.
            'echo "mysql-apt-config mysql-apt-config/unsupported-platform select abort" | debconf-set-selections',
            'provision.preseed_debconf',
            sudo: true
        );

        // Add MySQL APT repository
        $steps[] = $this->ssh->exec(
            $node,
            "wget -q {$aptConfigUrl} -O /tmp/mysql-apt-config.deb && ".
            'DEBIAN_FRONTEND=noninteractive dpkg -i /tmp/mysql-apt-config.deb 2>&1 && '.
            'apt-get update -qq 2>&1',
            'provision.mysql_repo',
            sudo: true,
            timeout: 120
        );

        // Pin the official MySQL repo at high priority so it always wins over Ubuntu packages
        $steps[] = $this->ssh->exec(
            $node,
            'echo -e "Package: mysql-*\\nPin: origin repo.mysql.com\\nPin-Priority: 1001\\n\\nPackage: mysql-shell*\\nPin: origin repo.mysql.com\\nPin-Priority: 1001" > /etc/apt/preferences.d/mysql.pref',
            'provision.pin_mysql_repo',
            sudo: true
        );

        // Install MySQL Server and Shell — pin to exact version if specified (can take several minutes)
        if ($pinnedMysqlVersion) {
            $installCmd = "DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server={$pinnedMysqlVersion} mysql-shell 2>&1";
        } else {
            $installCmd = 'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server mysql-shell 2>&1';
        }
        $steps[] = $this->ssh->exec($node, $installCmd, 'provision.mysql_install', sudo: true, timeout: 600);

        // Verify packages come from the official MySQL repo (not Ubuntu)
        $versionResult = $this->ssh->exec($node, 'mysql --version 2>&1', 'provision.check_version');
        $shellResult = $this->ssh->exec($node, 'mysqlsh --version 2>&1', 'provision.check_shell_version');

        // Get the exact installed package version for pinning
        $installedVersionResult = $this->ssh->exec(
            $node,
            "dpkg-query -W -f='\${Version}' mysql-server 2>/dev/null",
            'provision.get_installed_version'
        );

        // Check the source repository for each package
        $serverSourceResult = $this->ssh->exec($node, 'apt-cache policy mysql-server 2>&1', 'provision.verify_server_source');
        $shellSourceResult = $this->ssh->exec($node, 'apt-cache policy mysql-shell 2>&1', 'provision.verify_shell_source');

        $steps[] = $serverSourceResult;
        $steps[] = $shellSourceResult;

        $mysqlInstalled = $versionResult['success'];
        $shellInstalled = $shellResult['success'];

        // Verify packages are from the official repo.
        // Check for repo.mysql.com in the full policy output, or our pin priority (1001).
        $serverPolicy = $serverSourceResult['output'] ?? '';
        $shellPolicy = $shellSourceResult['output'] ?? '';
        $serverFromOfficial = str_contains($serverPolicy, 'repo.mysql.com') || str_contains($serverPolicy, '1001');
        $shellFromOfficial = str_contains($shellPolicy, 'repo.mysql.com') || str_contains($shellPolicy, '1001');

        $installedVersion = trim($installedVersionResult['output'] ?? '');

        $node->update([
            'mysql_installed' => $mysqlInstalled,
            'mysql_shell_installed' => $shellInstalled,
            'mysql_version' => $mysqlInstalled ? trim($versionResult['output']) : null,
        ]);

        return [
            'mysql_installed' => $mysqlInstalled,
            'mysql_shell_installed' => $shellInstalled,
            'mysql_version' => trim($versionResult['output'] ?? ''),
            'mysql_package_version' => $installedVersion,
            'apt_config_version' => $aptConfigVersion,
            'server_from_official_repo' => $serverFromOfficial,
            'shell_from_official_repo' => $shellFromOfficial,
            'steps' => $steps,
        ];
    }

    /**
     * Install MySQL Router on a node.
     *
     * If the MySQL APT repo is not already configured, it will be set up first
     * to ensure we get the official MySQL Router package.
     */
    public function installMysqlRouter(Node $node, ?string $aptConfigVersion = null): array
    {
        // Check if the MySQL APT repo is already set up
        $repoCheck = $this->ssh->exec(
            $node,
            'apt-cache policy mysql-router 2>/dev/null | grep -q "repo.mysql.com" && echo "REPO_OK" || echo "REPO_MISSING"',
            'provision.check_router_repo'
        );

        if (str_contains($repoCheck['output'], 'REPO_MISSING')) {
            // Set up the MySQL APT repo first
            if (! $aptConfigVersion) {
                $aptConfigVersion = $this->resolveLatestAptConfigVersion();
            }
            $aptConfigUrl = $this->getAptConfigUrl($aptConfigVersion);

            // Install prerequisites
            $this->ssh->exec(
                $node,
                'apt-get update -qq && apt-get install -y -qq wget gnupg lsb-release 2>&1',
                'provision.router_prerequisites',
                sudo: true,
                timeout: 120
            );

            // Import MySQL GPG key
            $this->ssh->exec(
                $node,
                'gpg --homedir /tmp --keyserver keyserver.ubuntu.com --recv-keys B7B3B788A8D3785C 2>&1 && '.
                'gpg --homedir /tmp --export B7B3B788A8D3785C | tee /usr/share/keyrings/mysql-archive-keyring.gpg > /dev/null 2>&1 && '.
                'gpg --homedir /tmp --export B7B3B788A8D3785C | apt-key add - 2>&1',
                'provision.router_gpg_key',
                sudo: true,
                timeout: 60
            );

            // Pre-seed debconf and add MySQL APT repository
            $this->ssh->exec(
                $node,
                'echo "mysql-apt-config mysql-apt-config/select-server select mysql-8.4-lts" | debconf-set-selections && '.
                'echo "mysql-apt-config mysql-apt-config/select-tools select Enabled" | debconf-set-selections && '.
                'echo "mysql-apt-config mysql-apt-config/select-preview select Disabled" | debconf-set-selections && '.
                'echo "mysql-apt-config mysql-apt-config/unsupported-platform select abort" | debconf-set-selections',
                'provision.router_preseed',
                sudo: true
            );

            $this->ssh->exec(
                $node,
                "wget -q {$aptConfigUrl} -O /tmp/mysql-apt-config.deb && ".
                'DEBIAN_FRONTEND=noninteractive dpkg -i /tmp/mysql-apt-config.deb 2>&1 && '.
                'apt-get update -qq 2>&1',
                'provision.router_add_repo',
                sudo: true,
                timeout: 120
            );
        }

        // Install MySQL Router
        $result = $this->ssh->exec(
            $node,
            'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-router 2>&1',
            'provision.router_install',
            sudo: true,
            timeout: 300
        );

        $versionResult = $this->ssh->exec($node, 'mysqlrouter --version 2>&1', 'provision.check_router_version');

        $installed = $versionResult['success'] && ! str_contains($versionResult['output'], 'not found');
        $node->update(['mysql_router_installed' => $installed]);

        return [
            'installed' => $installed,
            'version' => trim($versionResult['output'] ?? ''),
        ];
    }

    /**
     * Write the InnoDB Cluster MySQL configuration file on a node.
     */
    public function writeMysqlConfig(Node $node): array
    {
        $serverId = $node->server_id ?? $node->id;

        $config = <<<EOT
# InnoDB Cluster configuration - managed by PHPMyCluster
# Do not edit manually

[mysqld]
server-id = {$serverId}
report-host = {$node->host}

# GTID
gtid_mode = ON
enforce_gtid_consistency = ON

# Binary logging
log_bin = mysql-bin
binlog_checksum = NONE

# Group Replication prerequisites
disabled_storage_engines = "MyISAM,BLACKHOLE,FEDERATED,ARCHIVE,MEMORY"
plugin_load_add = group_replication.so

# Networking
bind-address = 0.0.0.0
mysqlx-bind-address = 0.0.0.0
port = {$node->mysql_port}
mysqlx-port = {$node->mysql_x_port}

# Replication tuning
replica_parallel_workers = 4
replica_preserve_commit_order = ON
EOT;

        $this->ssh->uploadFile($node, '/tmp/innodb-cluster.cnf', $config);

        // Detect which includedir MySQL uses — official repo uses /etc/mysql/conf.d/,
        // Ubuntu packages use /etc/mysql/mysql.conf.d/
        $confDirResult = $this->ssh->exec(
            $node,
            "grep -r '!includedir' /etc/mysql/my.cnf 2>/dev/null | tail -1 | awk '{print $2}'",
            'provision.detect_confdir'
        );
        $confDir = trim($confDirResult['output'] ?? '');
        if (empty($confDir) || ! str_starts_with($confDir, '/')) {
            // Fallback: check which directories exist
            $confDir = '/etc/mysql/conf.d/';
        }
        // Ensure trailing slash
        $confDir = rtrim($confDir, '/').'/';

        $result = $this->ssh->exec(
            $node,
            "mkdir -p {$confDir} && ".
            "mv /tmp/innodb-cluster.cnf {$confDir}innodb-cluster.cnf && ".
            "chown root:root {$confDir}innodb-cluster.cnf && ".
            "chmod 644 {$confDir}innodb-cluster.cnf",
            'provision.write_config',
            sudo: true
        );

        if ($result['success']) {
            $node->update(['mysql_configured' => true, 'server_id' => $serverId]);
        }

        return $result;
    }

    /**
     * Restart MySQL service on a node.
     */
    public function restartMysql(Node $node): array
    {
        return $this->ssh->exec(
            $node,
            'systemctl restart mysql 2>&1',
            'provision.restart_mysql',
            sudo: true
        );
    }

    /**
     * Bootstrap MySQL Router on a node and connect it to the cluster.
     */
    public function bootstrapRouter(Node $node, Node $primaryNode, string $clusterAdminPassword): array
    {
        // Create mysqlrouter system user if not exists
        $this->ssh->exec(
            $node,
            'id mysqlrouter &>/dev/null || useradd -r -s /bin/false mysqlrouter',
            'provision.router_user',
            sudo: true
        );

        // Bootstrap the router
        $result = $this->ssh->exec(
            $node,
            "mysqlrouter --bootstrap clusteradmin@{$primaryNode->host}:{$primaryNode->mysql_port} ".
            '--user=mysqlrouter '.
            '--conf-bind-address=0.0.0.0 '.
            '--force '.
            "<<< '{$clusterAdminPassword}' 2>&1",
            'provision.router_bootstrap',
            sudo: true
        );

        if ($result['success']) {
            // Enable and start the service
            $this->ssh->exec(
                $node,
                'systemctl enable mysqlrouter && systemctl restart mysqlrouter 2>&1',
                'provision.router_start',
                sudo: true
            );
        }

        return $result;
    }

    /**
     * Get MySQL Router status on a node.
     */
    public function getRouterStatus(Node $node): array
    {
        $result = $this->ssh->exec(
            $node,
            'systemctl is-active mysqlrouter 2>&1 && mysqlrouter --version 2>&1',
            'router.status'
        );

        $output = trim($result['output'] ?? '');
        // "inactive" and "failed" both contain no exact match for the word "active" on its own line
        // systemctl is-active outputs exactly "active" when running, "inactive" or "failed" otherwise
        $firstLine = strtolower(trim(explode("\n", $output)[0] ?? ''));

        return [
            'running' => $firstLine === 'active',
            'output' => $output,
        ];
    }
}
