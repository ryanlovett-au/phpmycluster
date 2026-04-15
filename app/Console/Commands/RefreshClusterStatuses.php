<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDbStatusJob;
use App\Jobs\RefreshRedisStatusJob;
use App\Jobs\RefreshRouterStatusJob;
use App\Jobs\RefreshUserListJob;
use App\Models\MysqlCluster;
use App\Models\RedisCluster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class RefreshClusterStatuses extends Command
{
    protected $signature = 'clusters:refresh-status';

    protected $description = 'Refresh the status of all active clusters by dispatching parallel jobs';

    public function handle(): int
    {
        $mysqlClusters = MysqlCluster::whereIn('status', ['online', 'degraded'])->get();
        $redisClusters = RedisCluster::whereIn('status', ['online', 'degraded'])->get();

        $totalClusters = $mysqlClusters->count() + $redisClusters->count();

        if ($totalClusters === 0) {
            $this->info('No active clusters to refresh.');

            return self::SUCCESS;
        }

        $this->info("Dispatching refresh jobs for {$totalClusters} cluster(s)...");

        // MySQL clusters
        foreach ($mysqlClusters as $cluster) {
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

            $this->info('Dispatched '.count($jobs)." MySQL job(s) for {$cluster->name}.");
        }

        // Redis clusters
        foreach ($redisClusters as $cluster) {
            Bus::batch([new RefreshRedisStatusJob($cluster)])
                ->name("Scheduled refresh: {$cluster->name}")
                ->allowFailures()
                ->dispatch();

            $this->info("Dispatched Redis refresh job for {$cluster->name}.");
        }

        return self::SUCCESS;
    }
}
