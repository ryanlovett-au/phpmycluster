<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDbStatusJob;
use App\Jobs\RefreshRouterStatusJob;
use App\Jobs\RefreshUserListJob;
use App\Models\Cluster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class RefreshClusterStatuses extends Command
{
    protected $signature = 'clusters:refresh-status';

    protected $description = 'Refresh the status of all active clusters by dispatching parallel jobs';

    public function handle(): int
    {
        $clusters = Cluster::whereIn('status', ['online', 'degraded'])->get();

        if ($clusters->isEmpty()) {
            $this->info('No active clusters to refresh.');

            return self::SUCCESS;
        }

        $this->info("Dispatching refresh jobs for {$clusters->count()} cluster(s)...");

        foreach ($clusters as $cluster) {
            $jobs = [];

            $jobs[] = new RefreshDbStatusJob($cluster);

            $routerNodes = $cluster->nodes()->where('role', 'access')->get();
            foreach ($routerNodes as $routerNode) {
                $jobs[] = new RefreshRouterStatusJob($routerNode);
            }

            $jobs[] = new RefreshUserListJob($cluster);

            Bus::batch($jobs)
                ->name("Scheduled refresh: {$cluster->name}")
                ->allowFailures()
                ->dispatch();

            $this->info('Dispatched '.count($jobs)." job(s) for {$cluster->name}.");
        }

        return self::SUCCESS;
    }
}
