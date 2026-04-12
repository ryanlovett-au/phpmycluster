<?php

namespace App\Livewire;

use App\Models\AuditLog;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogViewer extends Component
{
    use WithPagination;

    public ?int $clusterId = null;

    public ?int $nodeId = null;

    public string $actionFilter = '';

    public string $statusFilter = '';

    public function render()
    {
        $query = AuditLog::query()->with(['cluster', 'node'])->latest();

        if ($this->clusterId) {
            $query->where('cluster_id', $this->clusterId);
        }

        if ($this->nodeId) {
            $query->where('node_id', $this->nodeId);
        }

        if ($this->actionFilter) {
            $query->where('action', 'like', "%{$this->actionFilter}%");
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.audit-log-viewer', [
            'logs' => $query->paginate(25),
        ]);
    }
}
