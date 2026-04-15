<?php

namespace App\Services;

use App\Contracts\SshConnectable;
use App\Models\AuditLog;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class SshService
{
    protected ?SSH2 $connection = null;

    /**
     * Generate a new Ed25519 SSH keypair.
     * Returns ['private' => '...', 'public' => '...']
     */
    public function generateKeyPair(): array
    {
        $privateKey = EC::createKey('Ed25519');
        $publicKey = $privateKey->getPublicKey();

        return [
            'private' => $privateKey->toString('OpenSSH'),
            'public' => $publicKey->toString('OpenSSH', ['comment' => 'phpmycluster-'.now()->format('Ymd-His')]),
        ];
    }

    /**
     * Connect to a server via SSH.
     *
     * @codeCoverageIgnore Thin wrapper around phpseclib SSH2 — requires real network connection to test.
     */
    public function connect(Server $server, int $timeout = 10): SSH2
    {
        $ssh = new SSH2($server->host, $server->ssh_port, $timeout);

        $key = PublicKeyLoader::loadPrivateKey($server->ssh_private_key_encrypted);

        if (! $ssh->login($server->ssh_user, $key)) {
            throw new \RuntimeException("SSH authentication failed for {$server->ssh_user}@{$server->host}:{$server->ssh_port}");
        }

        $this->connection = $ssh;

        return $ssh;
    }

    /**
     * Connect via SFTP to upload/download files.
     *
     * @codeCoverageIgnore Thin wrapper around phpseclib SFTP — requires real network connection to test.
     */
    public function connectSftp(Server $server, int $timeout = 10): SFTP
    {
        $sftp = new SFTP($server->host, $server->ssh_port, $timeout);

        $key = PublicKeyLoader::loadPrivateKey($server->ssh_private_key_encrypted);

        if (! $sftp->login($server->ssh_user, $key)) {
            throw new \RuntimeException("SFTP authentication failed for {$server->ssh_user}@{$server->host}:{$server->ssh_port}");
        }

        return $sftp;
    }

    /**
     * Execute a command on a connectable entity (MysqlNode, RedisNode, etc.)
     * and return the output. Logs everything to audit_logs.
     */
    public function exec(SshConnectable $connectable, string $command, string $action = 'ssh.exec', bool $sudo = false, int $timeout = 300): array
    {
        $server = $connectable->getServer();
        $auditContext = $connectable->getAuditContext();
        $start = microtime(true);

        $auditLog = AuditLog::create(array_merge($auditContext, [
            'action' => $action,
            'status' => 'started',
            'command' => $this->sanitiseCommand($command),
        ]));

        try {
            $ssh = $this->connect($server);
            $ssh->setTimeout($timeout);

            $fullCommand = $sudo ? 'sudo bash -c '.escapeshellarg($command) : $command;
            $output = $ssh->exec($fullCommand);
            $exitCode = $ssh->getExitStatus();

            $durationMs = (int) ((microtime(true) - $start) * 1000);

            $auditLog->update([
                'status' => $exitCode === 0 ? 'success' : 'failed',
                'output' => $output,
                'error_message' => $exitCode !== 0 ? "Exit code: {$exitCode}" : null,
                'duration_ms' => $durationMs,
            ]);

            return [
                'success' => $exitCode === 0,
                'output' => $output,
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            $auditLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            Log::error("SSH exec failed on {$server->host}: {$e->getMessage()}");

            return [
                'success' => false,
                'output' => '',
                'exit_code' => -1,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ];
        }
    }

    /**
     * Test SSH connectivity to a server.
     */
    public function testConnection(Server $server): array
    {
        try {
            $ssh = $this->connect($server);
            $hostname = trim($ssh->exec('hostname'));
            $os = trim($ssh->exec('cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d= -f2 | tr -d \'"\''));

            return [
                'success' => true,
                'hostname' => $hostname,
                'os' => $os,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test SSH connectivity using raw credentials (no Server model needed).
     * Useful during setup wizard before the server is persisted.
     *
     * @codeCoverageIgnore Constructs SSH2 internally — requires real network connection to test the success path.
     */
    public function testConnectionDirect(string $host, int $port, string $user, string $privateKeyContent): array
    {
        try {
            $ssh = new SSH2($host, $port, 10);
            $key = PublicKeyLoader::loadPrivateKey($privateKeyContent);

            if (! $ssh->login($user, $key)) {
                return [
                    'success' => false,
                    'error' => "Authentication failed for {$user}@{$host}:{$port}",
                ];
            }

            $hostname = trim($ssh->exec('hostname'));
            $os = trim($ssh->exec('cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d= -f2 | tr -d \'"\''));

            return [
                'success' => true,
                'hostname' => $hostname,
                'os' => $os,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test network connectivity between two connectable entities.
     */
    public function testNodeConnectivity(SshConnectable $source, SshConnectable $target, int $port): array
    {
        $targetHost = $target->getServer()->host;
        $result = $this->exec(
            $source,
            "timeout 5 bash -c 'echo > /dev/tcp/{$targetHost}/{$port}' 2>&1 && echo 'OPEN' || echo 'CLOSED'",
            'connectivity.test'
        );

        return [
            'success' => $result['success'],
            'port_open' => str_contains($result['output'], 'OPEN'),
            'output' => $result['output'],
        ];
    }

    /**
     * Upload a file to a server via SFTP.
     */
    public function uploadFile(SshConnectable $connectable, string $remotePath, string $content): bool
    {
        $sftp = $this->connectSftp($connectable->getServer());

        return $sftp->put($remotePath, $content);
    }

    /**
     * Remove passwords and sensitive data from commands before logging.
     */
    protected function sanitiseCommand(string $command): string
    {
        $command = preg_replace('/--password[= ]+[\'"]?[^\s\'"]+[\'"]?/', '--password=***', $command);
        $command = preg_replace('/AdminPassword[\'"]?\s*:\s*[\'"][^\'"]+[\'"]/', 'AdminPassword: \'***\'', $command);

        return $command;
    }
}
