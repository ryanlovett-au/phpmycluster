<?php

namespace App\Services;

use App\Models\RedisNode;

/**
 * Stream Redis and Sentinel logs from nodes via SSH.
 */
class RedisLogStreamService
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Get the last N lines of the Redis server log.
     */
    public function getRedisLog(RedisNode $node, int $lines = 100): array
    {
        return $this->ssh->exec(
            $node,
            "tail -n {$lines} /var/log/redis/redis-server.log 2>&1 || echo 'Log file not found.'",
            'log.redis',
            sudo: true
        );
    }

    /**
     * Get the last N lines of the Sentinel log.
     */
    public function getSentinelLog(RedisNode $node, int $lines = 100): array
    {
        return $this->ssh->exec(
            $node,
            "tail -n {$lines} /var/log/redis/redis-sentinel.log 2>&1 || echo 'Log file not found.'",
            'log.sentinel',
            sudo: true
        );
    }

    /**
     * Get systemd journal logs for the Redis server service.
     */
    public function getSystemdRedisLog(RedisNode $node, int $lines = 100): array
    {
        return $this->ssh->exec(
            $node,
            "journalctl -u redis-server --no-pager -n {$lines} 2>&1 || echo 'No systemd logs found.'",
            'log.systemd_redis',
            sudo: true
        );
    }

    /**
     * Get systemd journal logs for the Sentinel service.
     */
    public function getSystemdSentinelLog(RedisNode $node, int $lines = 100): array
    {
        return $this->ssh->exec(
            $node,
            "journalctl -u redis-sentinel --no-pager -n {$lines} 2>&1 || echo 'No systemd logs found.'",
            'log.systemd_sentinel',
            sudo: true
        );
    }
}
