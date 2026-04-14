<?php

namespace App\Livewire;

use App\Models\Node;
use App\Services\LogStreamService;
use Livewire\Component;

class NodeLogs extends Component
{
    public Node $node;

    public string $logType = 'error'; // error, slow, general, systemd, router

    public int $lines = 100;

    public string $logContent = '';

    public bool $autoRefresh = false;

    public bool $loading = false;

    public function mount(Node $node)
    {
        $this->node = $node;
    }

    public function fetchLogs()
    {
        $this->loading = true;
        $logService = app(LogStreamService::class);

        $result = match ($this->logType) {
            'error' => $logService->getErrorLog($this->node, $this->lines),
            'slow' => $logService->getSlowLog($this->node, $this->lines),
            'general' => $logService->getGeneralLog($this->node, $this->lines),
            'systemd' => $logService->getSystemdLog($this->node, $this->lines),
            'router' => $logService->getRouterLog($this->node, $this->lines),
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
        return view('livewire.node-logs')
            ->layout('layouts.app', ['title' => $this->node->name.' - '.__('Logs')]);
    }
}
