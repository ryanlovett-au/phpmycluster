<?php

namespace App\Services;

use App\Models\Cluster;
use App\Models\Node;

/**
 * Wraps MySQL Shell (mysqlsh) AdminAPI commands.
 * All commands are executed via SSH on the target node — mysqlsh runs locally
 * on that node and connects to localhost, so no MySQL ports need to be open
 * from the control node.
 */
class MysqlShellService
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Run a mysqlsh JS command on a node and return parsed JSON output.
     */
    public function runJs(Node $node, string $jsCode, string $action, ?string $password = null): array
    {
        // Wrap JS code to output JSON
        $escapedJs = str_replace("'", "'\\''", $jsCode);

        $passwordEnv = $password ? 'MYSQLSH_PASSWORD='.escapeshellarg($password).' ' : '';

        $command = "{$passwordEnv}mysqlsh --no-wizard --js -e '{$escapedJs}' 2>&1";

        $result = $this->ssh->exec($node, $command, $action);

        // Attempt to extract JSON from output
        $json = $this->extractJson($result['output']);

        return [
            'success' => $result['success'],
            'data' => $json,
            'raw_output' => $result['output'],
            'exit_code' => $result['exit_code'],
        ];
    }

    /**
     * Check if a node instance is ready for InnoDB Cluster.
     */
    public function checkInstanceConfiguration(Node $node, string $password): array
    {
        $uri = "clusteradmin@localhost:{$node->mysql_port}";

        return $this->runJs($node, "
            try {
                var result = dba.checkInstanceConfiguration('{$uri}');
                print(JSON.stringify(result));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.check_instance', $password);
    }

    /**
     * Configure an instance for InnoDB Cluster (creates admin user, sets required vars).
     */
    public function configureInstance(Node $node, string $rootPassword, string $clusterAdminUser, string $clusterAdminPassword): array
    {
        return $this->runJs($node, "
            try {
                dba.configureInstance('root@localhost:{$node->mysql_port}', {
                    password: '{$rootPassword}',
                    clusterAdmin: '{$clusterAdminUser}',
                    clusterAdminPassword: '{$clusterAdminPassword}',
                    interactive: false,
                    restart: false
                });
                print(JSON.stringify({status: 'ok'}));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.configure_instance');
    }

    /**
     * Create a new InnoDB Cluster on the seed (primary) node.
     */
    public function createCluster(Node $seedNode, Cluster $cluster, string $password): array
    {
        $ipAllowlist = $cluster->buildIpAllowlist();

        return $this->runJs($seedNode, "
            try {
                shell.connect('clusteradmin@localhost:{$seedNode->mysql_port}', '{$password}');
                var c = dba.createCluster('{$cluster->name}', {
                    ipAllowlist: '{$ipAllowlist}',
                    communicationStack: '{$cluster->communication_stack}'
                });
                print(JSON.stringify(c.status()));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.create', $password);
    }

    /**
     * Get the full cluster status as JSON.
     */
    public function getClusterStatus(Node $node, string $password): array
    {
        return $this->runJs($node, "
            try {
                shell.connect('clusteradmin@localhost:{$node->mysql_port}', '{$password}');
                var c = dba.getCluster();
                var status = c.status({extended: 1});
                print(JSON.stringify(status));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.status', $password);
    }

    /**
     * Add an instance to the cluster. Run from the PRIMARY node.
     */
    public function addInstance(Node $primaryNode, Node $newNode, Cluster $cluster, string $password): array
    {
        $ipAllowlist = $cluster->buildIpAllowlist();

        return $this->runJs($primaryNode, "
            try {
                shell.connect('clusteradmin@localhost:{$primaryNode->mysql_port}', '{$password}');
                var c = dba.getCluster();
                c.addInstance('clusteradmin@{$newNode->host}:{$newNode->mysql_port}', {
                    password: '{$password}',
                    recoveryMethod: 'clone',
                    ipAllowlist: '{$ipAllowlist}'
                });
                print(JSON.stringify(c.status()));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.add_instance', $password);
    }

    /**
     * Remove an instance from the cluster.
     */
    public function removeInstance(Node $primaryNode, Node $targetNode, string $password, bool $force = false): array
    {
        $forceOption = $force ? ', force: true' : '';

        return $this->runJs($primaryNode, "
            try {
                shell.connect('clusteradmin@localhost:{$primaryNode->mysql_port}', '{$password}');
                var c = dba.getCluster();
                c.removeInstance('clusteradmin@{$targetNode->host}:{$targetNode->mysql_port}'{$forceOption});
                print(JSON.stringify({status: 'removed'}));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.remove_instance', $password);
    }

    /**
     * Rejoin an instance that has gone offline.
     */
    public function rejoinInstance(Node $primaryNode, Node $targetNode, string $password): array
    {
        return $this->runJs($primaryNode, "
            try {
                shell.connect('clusteradmin@localhost:{$primaryNode->mysql_port}', '{$password}');
                var c = dba.getCluster();
                c.rejoinInstance('clusteradmin@{$targetNode->host}:{$targetNode->mysql_port}');
                print(JSON.stringify(c.status()));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.rejoin_instance', $password);
    }

    /**
     * Rescan the cluster topology (detects unregistered/missing instances).
     */
    public function rescanCluster(Node $node, string $password): array
    {
        return $this->runJs($node, "
            try {
                shell.connect('clusteradmin@localhost:{$node->mysql_port}', '{$password}');
                var c = dba.getCluster();
                c.rescan({interactive: false});
                print(JSON.stringify(c.status()));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.rescan', $password);
    }

    /**
     * Force quorum using the given node (when majority is lost).
     */
    public function forceQuorum(Node $node, string $password): array
    {
        return $this->runJs($node, "
            try {
                shell.connect('clusteradmin@localhost:{$node->mysql_port}', '{$password}');
                var c = dba.getCluster();
                c.forceQuorumUsingPartitionOf('clusteradmin@localhost:{$node->mysql_port}');
                print(JSON.stringify(c.status()));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.force_quorum', $password);
    }

    /**
     * Reboot a cluster from a complete outage.
     */
    public function rebootCluster(Node $node, string $password): array
    {
        return $this->runJs($node, "
            try {
                shell.connect('clusteradmin@localhost:{$node->mysql_port}', '{$password}');
                var c = dba.rebootClusterFromCompleteOutage();
                print(JSON.stringify(c.status()));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.reboot', $password);
    }

    /**
     * Switch a cluster to single-primary or multi-primary mode.
     */
    public function switchToSinglePrimary(Node $node, string $password, ?string $newPrimaryHost = null): array
    {
        $arg = $newPrimaryHost ? "'clusteradmin@{$newPrimaryHost}'" : '';

        return $this->runJs($node, "
            try {
                shell.connect('clusteradmin@localhost:{$node->mysql_port}', '{$password}');
                var c = dba.getCluster();
                c.switchToSinglePrimaryMode({$arg});
                print(JSON.stringify(c.status()));
            } catch(e) {
                print(JSON.stringify({error: e.message}));
            }
        ", 'cluster.switch_primary', $password);
    }

    /**
     * Extract JSON from mysqlsh output (which may contain warnings/banners).
     */
    protected function extractJson(string $output): ?array
    {
        // Try to find a JSON object or array in the output
        if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])(?:\s*)$/', $output, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}
