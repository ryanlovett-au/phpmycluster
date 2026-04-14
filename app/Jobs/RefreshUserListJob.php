<?php

namespace App\Jobs;

use App\Models\Cluster;
use App\Services\MysqlShellService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshUserListJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(
        public Cluster $cluster,
    ) {}

    public function handle(MysqlShellService $mysqlShell): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $cluster = $this->cluster->fresh();
        $primary = $cluster->primaryNode();

        if (! $primary) {
            return;
        }

        $password = $cluster->cluster_admin_password_encrypted;

        try {
            $usersResult = $mysqlShell->listUsers($primary, $password);
            if ($usersResult['success'] && is_array($usersResult['data']) && ! isset($usersResult['data']['error'])) {
                Cache::put("cluster_users_{$cluster->id}", $usersResult['data'], now()->addMinutes(10));
            }

            $dbResult = $mysqlShell->listDatabases($primary, $password);
            if ($dbResult['success'] && is_array($dbResult['data']) && ! isset($dbResult['data']['error'])) {
                Cache::put("cluster_databases_{$cluster->id}", $dbResult['data'], now()->addMinutes(10));
            }
        } catch (\Throwable $e) {
            Log::warning("RefreshUserListJob: failed for cluster {$cluster->id}: {$e->getMessage()}");
        }
    }
}
