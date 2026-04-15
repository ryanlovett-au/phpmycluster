<?php

namespace App\Services;

use App\Models\Cluster;
use App\Models\Node;

/**
 * Wraps MySQL Shell (mysqlsh) AdminAPI commands.
 * All commands are executed via SSH on the target node — mysqlsh runs locally
 * on that node and connects to localhost, so no MySQL ports need to be open
 * from the control node.
 *
 * Uses JavaScript mode (--js) with MySQL Shell from the official MySQL APT
 * repository, which includes full JavaScript support.
 */
class MysqlShellService
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Run a mysqlsh JavaScript command on a node and return parsed JSON output.
     */
    public function runJs(Node $node, string $jsCode, string $action, ?string $password = null): array
    {
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
var result = dba.checkInstanceConfiguration('{$uri}');
print(JSON.stringify(result));
", 'cluster.check_instance', $password);
    }

    /**
     * Configure an instance for InnoDB Cluster (creates admin user, sets required vars).
     */
    public function configureInstance(Node $node, string $rootPassword, string $clusterAdminUser, string $clusterAdminPassword): array
    {
        // MySQL 8.4 uses auth_socket for root by default — root can only authenticate
        // via Unix socket as the OS root user (no password needed).
        // We pass the socket URI to configureInstance so it connects via auth_socket.
        // If the SSH user is not root, we fall back to password-based TCP auth.
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';
        $tcpUri = "root@localhost:{$node->mysql_port}";

        $jsCode = "
try {
    dba.configureInstance('{$socketUri}', {
        clusterAdmin: '{$clusterAdminUser}',
        clusterAdminPassword: '{$clusterAdminPassword}',
        restart: false
    });
    print(JSON.stringify({status: 'ok'}));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
";

        // Try socket auth first (works when SSH user is root and MySQL uses auth_socket)
        $result = $this->runJs($node, $jsCode, 'cluster.configure_instance');

        // Only fall back to password auth if the error is specifically "Access denied"
        // (not for other errors like hostname resolution issues)
        if (isset($result['data']['error']) && str_contains($result['data']['error'], 'Access denied')) {
            $jsCodeTcp = "
try {
    dba.configureInstance('{$tcpUri}', {
        clusterAdmin: '{$clusterAdminUser}',
        clusterAdminPassword: '{$clusterAdminPassword}',
        restart: false
    });
    print(JSON.stringify({status: 'ok'}));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
";
            $result = $this->runJs($node, $jsCodeTcp, 'cluster.configure_instance_tcp', $rootPassword);
        }

        return $result;
    }

    /**
     * Create a new InnoDB Cluster on the primary node.
     */
    public function createCluster(Node $primaryNode, Cluster $cluster, string $password): array
    {
        // ipAllowlist is only valid with XCOM communication stack, not MYSQL
        $options = "communicationStack: '{$cluster->communication_stack}'";
        if (strtoupper($cluster->communication_stack) === 'XCOM') {
            $ipAllowlist = $cluster->buildIpAllowlist();
            $options .= ", ipAllowlist: '{$ipAllowlist}'";
        }

        return $this->runJs($primaryNode, "
try {
    shell.connect('clusteradmin@localhost:{$primaryNode->mysql_port}', '{$password}');
    var c = dba.createCluster('{$cluster->name}', {{$options}});
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
    var status = c.status({extended: 2});
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
        // ipAllowlist is only valid with XCOM communication stack, not MYSQL
        $options = "recoveryMethod: 'clone'";
        if (strtoupper($cluster->communication_stack) === 'XCOM') {
            $ipAllowlist = $cluster->buildIpAllowlist();
            $options .= ", ipAllowlist: '{$ipAllowlist}'";
        }

        return $this->runJs($primaryNode, "
try {
    shell.connect('clusteradmin@localhost:{$primaryNode->mysql_port}', '{$password}');
    var c = dba.getCluster();
    c.addInstance('clusteradmin@{$newNode->host}:{$newNode->mysql_port}', {{$options}});
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
     * List MySQL users (excluding system accounts).
     * Connects as root via Unix socket (auth_socket) for full privileges.
     */
    public function listUsers(Node $node, string $password): array
    {
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';

        return $this->runJs($node, <<<'JSEOF'
try {
    shell.connect('root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock');
    var result = session.runSql("SELECT User, Host, authentication_string != '' AS has_password FROM mysql.user WHERE User NOT IN ('root', 'mysql.sys', 'mysql.infoschema', 'mysql.session', 'clusteradmin') AND User NOT LIKE 'mysql_%' AND User NOT LIKE 'mysql_innodb%' ORDER BY User, Host");
    var users = [];
    var row;
    while (row = result.fetchOne()) {
        var u = {user: row[0], host: row[1], has_password: row[2] == 1, database: '*.*', privileges: 'USAGE'};
        try {
            var grants = session.runSql("SHOW GRANTS FOR '" + row[0] + "'@'" + row[1] + "'");
            var g;
            while (g = grants.fetchOne()) {
                var line = g[0];
                if (line.indexOf('GRANT USAGE') === 0) continue;
                var m = line.match(/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/);
                if (m) {
                    u.privileges = m[1];
                    u.database = m[2].replace(/`/g, '');
                }
            }
        } catch(ge) {}
        users.push(u);
    }
    print(JSON.stringify(users));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
JSEOF, 'user.list');
    }

    /**
     * Get grants for a specific user.
     */
    public function getUserGrants(Node $node, string $password, string $user, string $host): array
    {
        $escapedUser = addslashes($user);
        $escapedHost = addslashes($host);
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';

        return $this->runJs($node, "
try {
    shell.connect('{$socketUri}');
    var result = session.runSql(\"SHOW GRANTS FOR '{$escapedUser}'@'{$escapedHost}'\");
    var grants = [];
    var row;
    while (row = result.fetchOne()) {
        grants.push(row[0]);
    }
    print(JSON.stringify({grants: grants}));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
", 'user.grants');
    }

    /**
     * Validate and sanitise a MySQL identifier (username, database, host).
     * Only allows safe characters to prevent SQL/shell injection.
     *
     * @throws \InvalidArgumentException if the identifier contains unsafe characters
     */
    protected function validateIdentifier(string $value, string $label): string
    {
        // Allow alphanumeric, underscore, hyphen, dot, percent (for host wildcards), at sign
        if (! preg_match('/^[a-zA-Z0-9_.\-%@\/]+$/', $value)) {
            throw new \InvalidArgumentException("{$label} contains invalid characters: {$value}");
        }

        return addslashes($value);
    }

    /**
     * Sanitise a password for use inside a MySQL Shell JS string.
     * Escapes quotes and backslashes for both JS and SQL contexts.
     */
    protected function sanitisePassword(string $password): string
    {
        // Escape backslash first, then single and double quotes for JS+SQL nesting
        return addslashes($password);
    }

    /**
     * Build a safe database scope string for GRANT/REVOKE statements.
     */
    protected function buildDbScope(string $database): string
    {
        if ($database === '*') {
            return '*.*';
        }

        $escaped = $this->validateIdentifier($database, 'Database name');

        return '`'.$escaped.'`.*';
    }

    /**
     * Create a MySQL user with specified privileges.
     * Connects as root via Unix socket for full DDL/GRANT privileges.
     */
    public function createUser(Node $node, string $password, string $user, string $userPassword, string $host, string $database, string $privileges): array
    {
        $escapedUser = $this->validateIdentifier($user, 'Username');
        $escapedHost = $this->validateIdentifier($host, 'Host');
        $escapedPass = $this->sanitisePassword($userPassword);
        $dbScope = $this->buildDbScope($database);
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';

        return $this->runJs($node, "
try {
    shell.connect('{$socketUri}');
    session.runSql(\"CREATE USER IF NOT EXISTS '{$escapedUser}'@'{$escapedHost}' IDENTIFIED BY '{$escapedPass}'\");
    session.runSql(\"GRANT {$privileges} ON {$dbScope} TO '{$escapedUser}'@'{$escapedHost}'\");
    session.runSql('FLUSH PRIVILEGES');
    print(JSON.stringify({status: 'ok'}));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
", 'user.create');
    }

    /**
     * Update a MySQL user's password and/or privileges.
     */
    public function updateUser(Node $node, string $password, string $user, string $host, ?string $newPassword, ?string $database, ?string $privileges): array
    {
        $escapedUser = $this->validateIdentifier($user, 'Username');
        $escapedHost = $this->validateIdentifier($host, 'Host');
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';

        $statements = '';

        if ($newPassword !== null && $newPassword !== '') {
            $escapedPass = $this->sanitisePassword($newPassword);
            $statements .= "session.runSql(\"ALTER USER '{$escapedUser}'@'{$escapedHost}' IDENTIFIED BY '{$escapedPass}'\");";
        }

        if ($database !== null && $privileges !== null) {
            $dbScope = $this->buildDbScope($database);
            $statements .= "session.runSql(\"REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$escapedUser}'@'{$escapedHost}'\");";
            $statements .= "session.runSql(\"GRANT {$privileges} ON {$dbScope} TO '{$escapedUser}'@'{$escapedHost}'\");";
        }

        $statements .= "session.runSql('FLUSH PRIVILEGES');";

        return $this->runJs($node, "
try {
    shell.connect('{$socketUri}');
    {$statements}
    print(JSON.stringify({status: 'ok'}));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
", 'user.update');
    }

    /**
     * Drop a MySQL user.
     */
    public function dropUser(Node $node, string $password, string $user, string $host): array
    {
        $escapedUser = $this->validateIdentifier($user, 'Username');
        $escapedHost = $this->validateIdentifier($host, 'Host');
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';

        return $this->runJs($node, "
try {
    shell.connect('{$socketUri}');
    session.runSql(\"DROP USER '{$escapedUser}'@'{$escapedHost}'\");
    session.runSql('FLUSH PRIVILEGES');
    print(JSON.stringify({status: 'ok'}));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
", 'user.drop');
    }

    /**
     * List databases (excluding system schemas).
     */
    public function listDatabases(Node $node, string $password): array
    {
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';

        return $this->runJs($node, "
try {
    shell.connect('{$socketUri}');
    var result = session.runSql(\"SHOW DATABASES\");
    var dbs = [];
    var row;
    var exclude = ['information_schema', 'mysql', 'performance_schema', 'sys', 'mysql_innodb_cluster_metadata'];
    while (row = result.fetchOne()) {
        if (exclude.indexOf(row[0]) === -1) dbs.push(row[0]);
    }
    print(JSON.stringify(dbs));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
", 'db.list');
    }

    /**
     * Create a database.
     */
    public function createDatabase(Node $node, string $password, string $database): array
    {
        $escapedDb = $this->validateIdentifier($database, 'Database name');
        $socketUri = 'root@localhost?socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock';

        return $this->runJs($node, "
try {
    shell.connect('{$socketUri}');
    session.runSql('CREATE DATABASE IF NOT EXISTS `{$escapedDb}`');
    print(JSON.stringify({status: 'ok'}));
} catch(e) {
    print(JSON.stringify({error: e.message}));
}
", 'db.create');
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
