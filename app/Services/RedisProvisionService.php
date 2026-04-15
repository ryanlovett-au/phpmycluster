<?php

namespace App\Services;

use App\Models\RedisCluster;
use App\Models\RedisNode;

class RedisProvisionService
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Detect the OS on the node.
     */
    public function detectOs(RedisNode $node): array
    {
        $result = $this->ssh->exec($node, 'cat /etc/os-release 2>/dev/null', 'redis.provision.detect_os');

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
     * Detect system hardware (RAM, CPU cores, OS) and store on the server.
     */
    public function detectSystemInfo(RedisNode $node): array
    {
        $ramResult = $this->ssh->exec(
            $node,
            "awk '/MemTotal/{printf \"%d\", \$2/1024}' /proc/meminfo 2>/dev/null",
            'redis.provision.detect_ram'
        );
        $ramMb = (int) trim($ramResult['output'] ?? '0');

        $cpuResult = $this->ssh->exec(
            $node,
            'nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo 2>/dev/null',
            'redis.provision.detect_cpu'
        );
        $cpuCores = (int) trim($cpuResult['output'] ?? '0');

        $osResult = $this->ssh->exec(
            $node,
            "grep PRETTY_NAME /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '\"'",
            'redis.provision.detect_os_name'
        );
        $osName = trim($osResult['output'] ?? '');

        $node->server->update([
            'ram_mb' => $ramMb ?: null,
            'cpu_cores' => $cpuCores ?: null,
            'os_name' => $osName ?: null,
        ]);

        return [
            'ram_mb' => $ramMb,
            'cpu_cores' => $cpuCores,
            'os_name' => $osName,
        ];
    }

    /**
     * Install Redis Server and Sentinel from the official Redis APT repository.
     * Uses packages.redis.io for the latest stable release.
     */
    public function installRedis(RedisNode $node): array
    {
        // Install prerequisites
        $this->ssh->exec(
            $node,
            'apt-get update -qq && apt-get install -y -qq curl gnupg lsb-release 2>&1',
            'redis.provision.prerequisites',
            sudo: true,
            timeout: 120
        );

        // Add the official Redis GPG key and repo
        $this->ssh->exec(
            $node,
            'curl -fsSL https://packages.redis.io/gpg | gpg --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg 2>&1 && '.
            'echo "deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb $(lsb_release -cs) main" > /etc/apt/sources.list.d/redis.list && '.
            'apt-get update -qq 2>&1',
            'redis.provision.add_repo',
            sudo: true,
            timeout: 120
        );

        // Install redis-server and redis-sentinel
        $result = $this->ssh->exec(
            $node,
            'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq redis-server redis-sentinel 2>&1',
            'redis.provision.install',
            sudo: true,
            timeout: 300
        );

        // Verify installation
        $versionResult = $this->ssh->exec($node, 'redis-server --version 2>&1', 'redis.provision.check_version');
        $sentinelResult = $this->ssh->exec($node, 'redis-sentinel --version 2>&1', 'redis.provision.check_sentinel_version');

        $redisInstalled = $versionResult['success'] && ! str_contains($versionResult['output'], 'not found');
        $sentinelInstalled = $sentinelResult['success'] && ! str_contains($sentinelResult['output'], 'not found');

        // Extract version number
        $version = null;
        if (preg_match('/v=(\d+\.\d+\.\d+)/', $versionResult['output'] ?? '', $m)) {
            $version = $m[1];
        }

        $node->update([
            'redis_installed' => $redisInstalled,
            'sentinel_installed' => $sentinelInstalled,
            'redis_version' => $version,
        ]);

        return [
            'redis_installed' => $redisInstalled,
            'sentinel_installed' => $sentinelInstalled,
            'redis_version' => $version,
            'output' => trim($versionResult['output'] ?? ''),
        ];
    }

    /**
     * Write Redis configuration for a node.
     * Configures bind address, port, AUTH password, and replication if replica.
     */
    public function writeRedisConfig(RedisNode $node, RedisCluster $cluster): array
    {
        $host = $node->server->host;
        $port = $node->redis_port;
        $authPassword = $cluster->auth_password_encrypted;

        $config = <<<EOT
# Redis configuration - managed by PHPMyCluster
# Do not edit manually

bind 0.0.0.0
port {$port}
protected-mode yes

# Authentication
requirepass {$authPassword}
masterauth {$authPassword}

# Persistence
save 3600 1
save 300 100
save 60 10000
appendonly yes
appendfsync everysec

# Memory
maxmemory-policy noeviction

# Logging
loglevel notice
logfile /var/log/redis/redis-server.log

# General
daemonize yes
supervised systemd
pidfile /run/redis/redis-server.pid
dir /var/lib/redis
EOT;

        // If this is a replica, add replicaof directive
        if ($node->role->value === 'replica') {
            $master = $cluster->masterNode();
            if ($master) {
                $config .= "\n\n# Replication\nreplicaof {$master->server->host} {$master->redis_port}\n";
            }
        }

        $this->ssh->uploadFile($node, '/tmp/redis-phpmycluster.conf', $config);

        $result = $this->ssh->exec(
            $node,
            'cp /etc/redis/redis.conf /etc/redis/redis.conf.bak 2>/dev/null; '.
            'mv /tmp/redis-phpmycluster.conf /etc/redis/redis.conf && '.
            'chown redis:redis /etc/redis/redis.conf && '.
            'chmod 640 /etc/redis/redis.conf',
            'redis.provision.write_config',
            sudo: true
        );

        if ($result['success']) {
            $node->update(['redis_configured' => true]);
        }

        return $result;
    }

    /**
     * Write Sentinel configuration for a node.
     */
    public function writeSentinelConfig(RedisNode $node, RedisCluster $cluster): array
    {
        $master = $cluster->masterNode();
        if (! $master) {
            return ['success' => false, 'output' => 'No master node found'];
        }

        $masterHost = $master->server->host;
        $masterPort = $master->redis_port;
        $masterName = $cluster->name;
        $quorum = $cluster->quorum;
        $downAfter = $cluster->down_after_milliseconds;
        $failoverTimeout = $cluster->failover_timeout;
        $sentinelPort = $node->sentinel_port;
        $sentinelPassword = $cluster->sentinel_password_encrypted;
        $authPassword = $cluster->auth_password_encrypted;

        $config = <<<EOT
# Sentinel configuration - managed by PHPMyCluster
# Do not edit manually

port {$sentinelPort}
daemonize yes
supervised systemd
pidfile /run/sentinel/redis-sentinel.pid
logfile /var/log/redis/redis-sentinel.log
dir /tmp

# Monitor the master
sentinel monitor {$masterName} {$masterHost} {$masterPort} {$quorum}
sentinel auth-pass {$masterName} {$authPassword}
sentinel down-after-milliseconds {$masterName} {$downAfter}
sentinel failover-timeout {$masterName} {$failoverTimeout}
sentinel parallel-syncs {$masterName} 1
EOT;

        if ($sentinelPassword) {
            $config .= "\n\n# Sentinel authentication\nrequirepass {$sentinelPassword}\n";
        }

        $this->ssh->uploadFile($node, '/tmp/sentinel-phpmycluster.conf', $config);

        $result = $this->ssh->exec(
            $node,
            'cp /etc/redis/sentinel.conf /etc/redis/sentinel.conf.bak 2>/dev/null; '.
            'mkdir -p /run/sentinel && chown redis:redis /run/sentinel; '.
            'mv /tmp/sentinel-phpmycluster.conf /etc/redis/sentinel.conf && '.
            'chown redis:redis /etc/redis/sentinel.conf && '.
            'chmod 640 /etc/redis/sentinel.conf',
            'redis.provision.write_sentinel_config',
            sudo: true
        );

        return $result;
    }

    /**
     * Restart Redis service on a node.
     */
    public function restartRedis(RedisNode $node): array
    {
        return $this->ssh->exec(
            $node,
            'systemctl restart redis-server 2>&1',
            'redis.provision.restart_redis',
            sudo: true
        );
    }

    /**
     * Restart Sentinel service on a node.
     */
    public function restartSentinel(RedisNode $node): array
    {
        return $this->ssh->exec(
            $node,
            'systemctl restart redis-sentinel 2>&1',
            'redis.provision.restart_sentinel',
            sudo: true
        );
    }

    /**
     * Get Redis service status on a node.
     */
    public function getRedisStatus(RedisNode $node): array
    {
        $result = $this->ssh->exec(
            $node,
            'systemctl is-active redis-server 2>&1',
            'redis.status'
        );

        $firstLine = strtolower(trim(explode("\n", $result['output'] ?? '')[0] ?? ''));

        return [
            'running' => $firstLine === 'active',
            'output' => trim($result['output'] ?? ''),
        ];
    }

    /**
     * Get Sentinel service status on a node.
     */
    public function getSentinelStatus(RedisNode $node): array
    {
        $result = $this->ssh->exec(
            $node,
            'systemctl is-active redis-sentinel 2>&1',
            'sentinel.status'
        );

        $firstLine = strtolower(trim(explode("\n", $result['output'] ?? '')[0] ?? ''));

        return [
            'running' => $firstLine === 'active',
            'output' => trim($result['output'] ?? ''),
        ];
    }
}
