<?php

namespace App\Jobs;

use App\Models\MysqlCluster;
use App\Services\MysqlShellService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshDbStatusJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public MysqlCluster $cluster,
    ) {}

    public function handle(MysqlShellService $mysqlShell): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $cluster = $this->cluster->fresh();
        $password = $cluster->cluster_admin_password_encrypted;

        $dbNodes = $cluster->nodes()
            ->whereIn('role', ['primary', 'secondary'])
            ->orderByRaw("CASE role WHEN 'primary' THEN 0 WHEN 'secondary' THEN 1 ELSE 2 END")
            ->get();

        if ($dbNodes->isEmpty()) {
            return;
        }

        $lastError = null;
        foreach ($dbNodes as $node) {
            try {
                $result = $mysqlShell->getClusterStatus($node, $password);

                if ($result['success'] && $result['data'] && ! isset($result['data']['error'])) {
                    $cluster->update([
                        'last_status_json' => $result['data'],
                        'last_checked_at' => now(),
                    ]);

                    // Update individual node statuses
                    $topology = $result['data']['defaultReplicaSet']['topology'] ?? [];
                    foreach ($topology as $address => $memberData) {
                        $host = explode(':', $address)[0];
                        $dbNode = $cluster->nodes()->where('host', $host)->first();
                        if ($dbNode) {
                            $memberStatus = strtolower($memberData['status'] ?? 'unknown');
                            $role = strtolower($memberData['memberRole'] ?? 'secondary');
                            $dbNode->update([
                                'status' => $memberStatus === 'online' ? 'online' : ($memberStatus === 'recovering' ? 'recovering' : 'error'),
                                'role' => $role === 'primary' ? 'primary' : 'secondary',
                                'last_health_json' => $memberData,
                                'last_checked_at' => now(),
                            ]);
                        }
                    }

                    // Derive overall cluster status
                    $replicaSetStatus = $result['data']['defaultReplicaSet']['status'] ?? null;
                    if ($replicaSetStatus) {
                        $clusterStatus = match ($replicaSetStatus) {
                            'OK', 'OK_NO_TOLERANCE' => 'online',
                            'OK_PARTIAL', 'OK_NO_TOLERANCE_PARTIAL' => 'degraded',
                            'NO_QUORUM' => 'degraded',
                            'OFFLINE', 'ERROR' => 'offline',
                            default => $cluster->status->value,
                        };
                        $cluster->update(['status' => $clusterStatus]);
                    }

                    // Store status JSON in cache for the frontend
                    Cache::put("cluster_status_{$cluster->id}", $result['data'], now()->addMinutes(10));

                    return;
                }

                $lastError = $result['data']['error'] ?? $result['raw_output'];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        Log::warning("RefreshDbStatusJob: all nodes failed for cluster {$cluster->id}: {$lastError}");
    }
}
