<?php

namespace App\Livewire;

use App\Jobs\RefreshDbStatusJob;
use App\Jobs\RefreshRedisStatusJob;
use App\Jobs\RefreshRouterStatusJob;
use App\Jobs\RefreshUserListJob;
use App\Models\AuditLog;
use App\Models\MysqlCluster;
use App\Models\RedisCluster;
use Illuminate\Support\Facades\Bus;
use Livewire\Component;

class Dashboard extends Component
{
    public bool $refreshing = false;

    public ?string $refreshBatchId = null;

    public ?string $refreshMessage = null;

    /**
     * Refresh status for all active clusters by dispatching background jobs via Bus::batch.
     */
    public function refreshAll(): void
    {
        $mysqlClusters = MysqlCluster::whereIn('status', ['online', 'degraded'])->with('nodes')->get();
        $redisClusters = RedisCluster::whereIn('status', ['online', 'degraded'])->get();

        $jobs = [];

        // MySQL clusters: DB status + router checks + user list
        foreach ($mysqlClusters as $cluster) {
            $jobs[] = new RefreshDbStatusJob($cluster);

            foreach ($cluster->nodes->where('role.value', 'access') as $routerNode) {
                $jobs[] = new RefreshRouterStatusJob($routerNode);
            }

            $jobs[] = new RefreshUserListJob($cluster);
        }

        // Redis clusters
        foreach ($redisClusters as $cluster) {
            $jobs[] = new RefreshRedisStatusJob($cluster);
        }

        if (empty($jobs)) {
            $this->refreshMessage = __('No active clusters to refresh.');

            return;
        }

        try {
            $batch = Bus::batch($jobs)
                ->name('Dashboard refresh')
                ->allowFailures()
                ->dispatch();

            $this->refreshing = true;
            $this->refreshBatchId = $batch->id;
            $this->refreshMessage = null;
        } catch (\Throwable $e) {
            $this->refreshMessage = __('Failed to dispatch refresh: :error', ['error' => $e->getMessage()]);
            $this->refreshing = false;
        }
    }

    /**
     * Poll the refresh batch for completion.
     */
    public function pollRefresh(): void
    {
        if (! $this->refreshBatchId) {
            return;
        }

        $batch = Bus::findBatch($this->refreshBatchId);

        if (! $batch || $batch->finished()) {
            $failedJobs = $batch?->failedJobs ?? 0;
            if ($failedJobs > 0) {
                $this->refreshMessage = __('Refreshed with :count failed check(s).', ['count' => $failedJobs]);
            } else {
                $this->refreshMessage = __('All cluster statuses refreshed.');
            }

            $this->refreshing = false;
            $this->refreshBatchId = null;
        }
    }

    public function render()
    {
        return view('livewire.dashboard', [
            'mysqlClusters' => MysqlCluster::with(['nodes'])->get(),
            'redisClusters' => RedisCluster::with(['nodes'])->get(),
            'recentLogs' => AuditLog::latest()->limit(20)->get(),
        ])->layout('layouts.app', ['title' => __('Dashboard')]);
    }
}
