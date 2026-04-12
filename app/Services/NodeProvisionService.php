<?php

namespace App\Services;

use App\Models\Node;

/**
 * Handles provisioning a fresh Debian/Ubuntu node with MySQL 8.4,
 * MySQL Shell, and MySQL Router.
 */
class NodeProvisionService
{
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
     * Install MySQL 8.4 Server on a Debian/Ubuntu node.
     */
    public function installMysql(Node $node): array
    {
        $steps = [];

        // Install prerequisites
        $steps[] = $this->ssh->exec(
            $node,
            'apt-get update -qq && apt-get install -y -qq wget gnupg lsb-release 2>&1',
            'provision.prerequisites',
            sudo: true
        );

        // Add MySQL APT repository
        $steps[] = $this->ssh->exec(
            $node,
            'wget -q https://dev.mysql.com/get/mysql-apt-config_0.8.32-1_all.deb -O /tmp/mysql-apt-config.deb && '.
            'DEBIAN_FRONTEND=noninteractive dpkg -i /tmp/mysql-apt-config.deb 2>&1 && '.
            'apt-get update -qq 2>&1',
            'provision.mysql_repo',
            sudo: true
        );

        // Install MySQL Server
        $steps[] = $this->ssh->exec(
            $node,
            'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-server mysql-shell 2>&1',
            'provision.mysql_install',
            sudo: true
        );

        // Check installation
        $versionResult = $this->ssh->exec($node, 'mysql --version 2>&1', 'provision.check_version');
        $shellResult = $this->ssh->exec($node, 'mysqlsh --version 2>&1', 'provision.check_shell_version');

        $mysqlInstalled = $versionResult['success'];
        $shellInstalled = $shellResult['success'];

        $node->update([
            'mysql_installed' => $mysqlInstalled,
            'mysql_shell_installed' => $shellInstalled,
            'mysql_version' => $mysqlInstalled ? trim($versionResult['output']) : null,
        ]);

        return [
            'mysql_installed' => $mysqlInstalled,
            'mysql_shell_installed' => $shellInstalled,
            'mysql_version' => trim($versionResult['output'] ?? ''),
            'steps' => $steps,
        ];
    }

    /**
     * Install MySQL Router on a node.
     */
    public function installMysqlRouter(Node $node): array
    {
        $result = $this->ssh->exec(
            $node,
            'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-router 2>&1',
            'provision.router_install',
            sudo: true
        );

        $versionResult = $this->ssh->exec($node, 'mysqlrouter --version 2>&1', 'provision.check_router_version');

        $installed = $versionResult['success'];
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
binlog_format = ROW
binlog_checksum = NONE
log_slave_updates = ON

# Group Replication prerequisites
disabled_storage_engines = "MyISAM,BLACKHOLE,FEDERATED,ARCHIVE,MEMORY"
plugin_load_add = group_replication.so

# Networking
bind-address = 0.0.0.0
mysqlx-bind-address = 0.0.0.0
port = {$node->mysql_port}
mysqlx-port = {$node->mysql_x_port}

# Performance tuning for Group Replication
slave_parallel_workers = 4
slave_preserve_commit_order = ON
transaction_write_set_extraction = XXHASH64
EOT;

        $this->ssh->uploadFile($node, '/tmp/innodb-cluster.cnf', $config);

        $result = $this->ssh->exec(
            $node,
            'mv /tmp/innodb-cluster.cnf /etc/mysql/mysql.conf.d/innodb-cluster.cnf && '.
            'chown root:root /etc/mysql/mysql.conf.d/innodb-cluster.cnf && '.
            'chmod 644 /etc/mysql/mysql.conf.d/innodb-cluster.cnf',
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

        return [
            'running' => str_contains($result['output'], 'active'),
            'output' => $result['output'],
        ];
    }
}
