<?php

namespace App\Livewire;

use App\Models\RedisNode;
use App\Services\SshService;
use Livewire\Component;

class RedisNodeLogs extends Component
{
    public RedisNode $node;

    public string $logType = 'redis'; // redis, sentinel, systemd-redis, systemd-sentinel

    public int $lines = 100;

    public string $logContent = '';

    public bool $autoRefresh = false;

    public bool $loading = false;

    public function mount(RedisNode $node)
    {
        $this->node = $node->load(['cluster', 'server']);
    }

    public function fetchLogs()
    {
        $this->loading = true;
        $ssh = app(SshService::class);

        $command = match ($this->logType) {
            'redis' => "tail -n {$this->lines} /var/log/redis/redis-server.log 2>/dev/null || echo 'Log file not found.'",
            'sentinel' => "tail -n {$this->lines} /var/log/redis/redis-sentinel.log 2>/dev/null || echo 'Log file not found.'",
            'systemd-redis' => "journalctl -u redis-server --no-pager -n {$this->lines} 2>/dev/null || echo 'No systemd logs found.'",
            'systemd-sentinel' => "journalctl -u redis-sentinel --no-pager -n {$this->lines} 2>/dev/null || echo 'No systemd logs found.'",
            default => 'echo "Unknown log type."',
        };

        try {
            $result = $ssh->exec(
                $this->node,
                $command,
                'logs.fetch.'.$this->logType,
                sudo: true
            );

            $this->logContent = $result['output'] ?? $result['error'] ?? 'No output.';
        } catch (\Throwable $e) {
            $this->logContent = 'Error fetching logs: '.$e->getMessage();
        }

        $this->loading = false;
    }

    public function setLogType(string $type)
    {
        $this->logType = $type;
        $this->fetchLogs();
    }

    public function render()
    {
        return view('livewire.redis-node-logs')
            ->layout('layouts.app', ['title' => $this->node->name.' - '.__('Logs')]);
    }
}
