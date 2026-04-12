<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Cluster;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.dashboard', [
            'clusters' => Cluster::with(['nodes'])->get(),
            'recentLogs' => AuditLog::latest()->limit(20)->get(),
        ]);
    }
}
