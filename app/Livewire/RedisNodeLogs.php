<?php

namespace App\Livewire;

use App\Models\RedisNode;
use App\Services\RedisLogStreamService;
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
        $this->node = $node;
    }

    public function fetchLogs()
    {
        $this->loading = true;
        $logService = app(RedisLogStreamService::class);

        $result = match ($this->logType) {
            'redis' => $logService->getRedisLog($this->node, $this->lines),
            'sentinel' => $logService->getSentinelLog($this->node, $this->lines),
            'systemd-redis' => $logService->getSystemdRedisLog($this->node, $this->lines),
            'systemd-sentinel' => $logService->getSystemdSentinelLog($this->node, $this->lines),
            default => ['output' => 'Unknown log type.'],
        };

        $this->logContent = $result['output'] ?? $result['error'] ?? 'No output.';
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
