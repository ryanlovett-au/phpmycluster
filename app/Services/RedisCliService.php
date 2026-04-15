<?php

namespace App\Services;

use App\Models\RedisNode;

/**
 * Wraps redis-cli commands executed via SSH on target nodes.
 * All commands are executed via the SshService, which handles SSH
 * connection management and audit logging.
 */
class RedisCliService
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Run a redis-cli command on a node and return the result.
     */
    public function run(RedisNode $node, string $command, string $action, ?string $password = null): array
    {
        $host = $node->server->host;
        $port = $node->redis_port;

        $auth = $password ? ' -a '.escapeshellarg($password).' --no-auth-warning' : '';
        $escapedCommand = escapeshellarg($command);

        $cli = "redis-cli -h {$host} -p {$port}{$auth} {$escapedCommand}";

        $result = $this->ssh->exec($node, $cli, $action);

        return [
            'success' => $result['success'],
            'output' => trim($result['output'] ?? ''),
            'exit_code' => $result['exit_code'],
        ];
    }

    /**
     * Test connectivity with PING.
     */
    public function ping(RedisNode $node, ?string $password = null): array
    {
        return $this->run($node, 'PING', 'redis.ping', $password);
    }

    /**
     * Run INFO command and parse key=value output into an associative array.
     */
    public function getInfo(RedisNode $node, ?string $section = null, ?string $password = null): array
    {
        $command = $section ? "INFO {$section}" : 'INFO';
        $result = $this->run($node, $command, 'redis.info', $password);

        if (! $result['success']) {
            return $result;
        }

        $result['data'] = $this->parseInfoOutput($result['output']);

        return $result;
    }

    /**
     * Run INFO replication and parse master/replica details.
     */
    public function getReplicationInfo(RedisNode $node, ?string $password = null): array
    {
        return $this->getInfo($node, 'replication', $password);
    }

    /**
     * Run SENTINEL masters on the sentinel port and parse the output.
     */
    public function getSentinelMasters(RedisNode $node, ?string $password = null): array
    {
        $host = $node->server->host;
        $port = $node->sentinel_port;

        $auth = $password ? ' -a '.escapeshellarg($password).' --no-auth-warning' : '';
        $cli = "redis-cli -h {$host} -p {$port}{$auth} SENTINEL masters";

        $result = $this->ssh->exec($node, $cli, 'sentinel.masters');

        if (! $result['success']) {
            return [
                'success' => false,
                'output' => trim($result['output'] ?? ''),
                'exit_code' => $result['exit_code'],
            ];
        }

        $output = trim($result['output'] ?? '');

        return [
            'success' => true,
            'output' => $output,
            'exit_code' => $result['exit_code'],
            'data' => $this->parseSentinelOutput($output),
        ];
    }

    /**
     * Run SENTINEL master {masterName} and return parsed status.
     */
    public function getSentinelStatus(RedisNode $node, string $masterName, ?string $password = null): array
    {
        $host = $node->server->host;
        $port = $node->sentinel_port;

        $auth = $password ? ' -a '.escapeshellarg($password).' --no-auth-warning' : '';
        $escapedName = escapeshellarg($masterName);
        $cli = "redis-cli -h {$host} -p {$port}{$auth} SENTINEL master {$escapedName}";

        $result = $this->ssh->exec($node, $cli, 'sentinel.status');

        if (! $result['success']) {
            return [
                'success' => false,
                'output' => trim($result['output'] ?? ''),
                'exit_code' => $result['exit_code'],
            ];
        }

        $output = trim($result['output'] ?? '');

        return [
            'success' => true,
            'output' => $output,
            'exit_code' => $result['exit_code'],
            'data' => $this->parseSentinelOutput($output),
        ];
    }

    /**
     * Run SENTINEL replicas {masterName} and return parsed output.
     */
    public function getSentinelReplicas(RedisNode $node, string $masterName, ?string $password = null): array
    {
        $host = $node->server->host;
        $port = $node->sentinel_port;

        $auth = $password ? ' -a '.escapeshellarg($password).' --no-auth-warning' : '';
        $escapedName = escapeshellarg($masterName);
        $cli = "redis-cli -h {$host} -p {$port}{$auth} SENTINEL replicas {$escapedName}";

        $result = $this->ssh->exec($node, $cli, 'sentinel.replicas');

        if (! $result['success']) {
            return [
                'success' => false,
                'output' => trim($result['output'] ?? ''),
                'exit_code' => $result['exit_code'],
            ];
        }

        $output = trim($result['output'] ?? '');

        return [
            'success' => true,
            'output' => $output,
            'exit_code' => $result['exit_code'],
            'data' => $this->parseSentinelOutput($output),
        ];
    }

    /**
     * Trigger a manual failover for the given master.
     */
    public function sentinelFailover(RedisNode $node, string $masterName, ?string $password = null): array
    {
        $host = $node->server->host;
        $port = $node->sentinel_port;

        $auth = $password ? ' -a '.escapeshellarg($password).' --no-auth-warning' : '';
        $escapedName = escapeshellarg($masterName);
        $cli = "redis-cli -h {$host} -p {$port}{$auth} SENTINEL failover {$escapedName}";

        $result = $this->ssh->exec($node, $cli, 'sentinel.failover');

        return [
            'success' => $result['success'],
            'output' => trim($result['output'] ?? ''),
            'exit_code' => $result['exit_code'],
        ];
    }

    /**
     * Reset sentinel state for the given master.
     */
    public function sentinelReset(RedisNode $node, string $masterName, ?string $password = null): array
    {
        $host = $node->server->host;
        $port = $node->sentinel_port;

        $auth = $password ? ' -a '.escapeshellarg($password).' --no-auth-warning' : '';
        $escapedName = escapeshellarg($masterName);
        $cli = "redis-cli -h {$host} -p {$port}{$auth} SENTINEL reset {$escapedName}";

        $result = $this->ssh->exec($node, $cli, 'sentinel.reset');

        return [
            'success' => $result['success'],
            'output' => trim($result['output'] ?? ''),
            'exit_code' => $result['exit_code'],
        ];
    }

    /**
     * Flush Sentinel configuration to disk.
     */
    public function sentinelFlushConfig(RedisNode $node, ?string $password = null): array
    {
        $host = $node->server->host;
        $port = $node->sentinel_port;

        $auth = $password ? ' -a '.escapeshellarg($password).' --no-auth-warning' : '';
        $cli = "redis-cli -h {$host} -p {$port}{$auth} SENTINEL flushconfig";

        $result = $this->ssh->exec($node, $cli, 'sentinel.flushconfig');

        return [
            'success' => $result['success'],
            'output' => trim($result['output'] ?? ''),
            'exit_code' => $result['exit_code'],
        ];
    }

    /**
     * Trigger a BGSAVE on a node.
     */
    public function bgsave(RedisNode $node, ?string $password = null): array
    {
        return $this->run($node, 'BGSAVE', 'redis.bgsave', $password);
    }

    /**
     * Trigger AOF rewrite on a node.
     */
    public function bgrewriteaof(RedisNode $node, ?string $password = null): array
    {
        return $this->run($node, 'BGREWRITEAOF', 'redis.bgrewriteaof', $password);
    }

    /**
     * Purge memory (release unused memory back to OS).
     */
    public function memoryPurge(RedisNode $node, ?string $password = null): array
    {
        return $this->run($node, 'MEMORY PURGE', 'redis.memory_purge', $password);
    }

    /**
     * Set REPLICAOF to force a replica to re-sync with a master.
     */
    public function replicaOf(RedisNode $node, string $masterHost, int $masterPort, ?string $password = null): array
    {
        $escapedHost = escapeshellarg($masterHost);

        return $this->run($node, "REPLICAOF {$escapedHost} {$masterPort}", 'redis.replicaof', $password);
    }

    /**
     * Run CONFIG SET on a node.
     */
    public function configSet(RedisNode $node, string $key, string $value, ?string $password = null): array
    {
        $escapedKey = escapeshellarg($key);
        $escapedValue = escapeshellarg($value);

        return $this->run($node, "CONFIG SET {$escapedKey} {$escapedValue}", 'redis.config_set', $password);
    }

    /**
     * Persist the current configuration to disk via CONFIG REWRITE.
     */
    public function configRewrite(RedisNode $node, ?string $password = null): array
    {
        return $this->run($node, 'CONFIG REWRITE', 'redis.config_rewrite', $password);
    }

    /**
     * Parse Redis INFO output (key=value lines) into an associative array.
     */
    protected function parseInfoOutput(string $output): array
    {
        $data = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and section headers (# Server, # Replication, etc.)
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $data[trim($key)] = trim($value);
            }
        }

        return $data;
    }

    /**
     * Parse Redis Sentinel's alternating key/value line format into an associative array.
     *
     * Sentinel outputs data as pairs of lines where odd lines are keys
     * and even lines are values:
     *   name
     *   mymaster
     *   ip
     *   10.0.0.1
     *   port
     *   6379
     */
    protected function parseSentinelOutput(string $output): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            fn ($line) => $line !== ''
        ));

        $data = [];

        for ($i = 0; $i < count($lines) - 1; $i += 2) {
            $data[$lines[$i]] = $lines[$i + 1];
        }

        return $data;
    }
}
