<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\MysqlCluster;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

class Dashboard extends Component
{
    public bool $refreshing = false;

    public ?string $refreshMessage = null;

    /**
     * Refresh status for all active clusters by dispatching background jobs.
     */
    public function refreshAll(): void
    {
        Artisan::call('clusters:refresh-status');

        $this->refreshMessage = __('Refresh jobs dispatched for all active clusters.');
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'clusters' => MysqlCluster::with(['nodes'])->get(),
            'recentLogs' => AuditLog::latest()->limit(20)->get(),
        ])->layout('layouts.app', ['title' => __('Dashboard')]);
    }
}
