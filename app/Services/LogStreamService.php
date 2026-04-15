<?php

namespace App\Services;

use App\Models\MysqlNode;

/**
 * Stream MySQL error logs and general logs from nodes via SSH.
 */
class LogStreamService
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Get the last N lines of the MySQL error log.
     */
    public function getErrorLog(MysqlNode $node, int $lines = 100): array
    {
        // MySQL error log location varies - check common locations
        $result = $this->ssh->exec(
            $node,
            "mysql -N -e \"SELECT @@log_error\" 2>/dev/null || echo '/var/log/mysql/error.log'",
            'log.find_error_log'
        );

        $logPath = trim($result['output']);
        if (empty($logPath) || $logPath === 'stderr') {
            $logPath = '/var/log/mysql/error.log';
        }

        return $this->ssh->exec(
            $node,
            "tail -n {$lines} {$logPath} 2>&1",
            'log.error_log',
            sudo: true
        );
    }

    /**
     * Get the last N lines of the MySQL slow query log.
     */
    public function getSlowLog(MysqlNode $node, int $lines = 100): array
    {
        $result = $this->ssh->exec(
            $node,
            "mysql -N -e \"SELECT @@slow_query_log_file\" 2>/dev/null || echo '/var/log/mysql/mysql-slow.log'",
            'log.find_slow_log'
        );

        $logPath = trim($result['output']);

        return $this->ssh->exec(
            $node,
            "tail -n {$lines} {$logPath} 2>&1",
            'log.slow_log',
            sudo: true
        );
    }

    /**
     * Get the MySQL general log (last N lines).
     */
    public function getGeneralLog(MysqlNode $node, int $lines = 100): array
    {
        $result = $this->ssh->exec(
            $node,
            "mysql -N -e \"SELECT @@general_log_file\" 2>/dev/null || echo '/var/log/mysql/mysql.log'",
            'log.find_general_log'
        );

        $logPath = trim($result['output']);

        return $this->ssh->exec(
            $node,
            "tail -n {$lines} {$logPath} 2>&1",
            'log.general_log',
            sudo: true
        );
    }

    /**
     * Get system journal logs for MySQL service.
     */
    public function getSystemdLog(MysqlNode $node, int $lines = 100): array
    {
        return $this->ssh->exec(
            $node,
            "journalctl -u mysql --no-pager -n {$lines} 2>&1",
            'log.systemd',
            sudo: true
        );
    }

    /**
     * Get MySQL Router logs from a node.
     */
    public function getRouterLog(MysqlNode $node, int $lines = 100): array
    {
        return $this->ssh->exec(
            $node,
            "tail -n {$lines} /var/log/mysqlrouter/mysqlrouter.log 2>&1 || journalctl -u mysqlrouter --no-pager -n {$lines} 2>&1",
            'log.router',
            sudo: true
        );
    }
}
