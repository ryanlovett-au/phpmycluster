<?php

namespace App\Jobs;

use App\Models\RedisCluster;
use App\Services\RedisCliService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshRedisStatusJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public RedisCluster $cluster,
    ) {}

    public function handle(RedisCliService $redisCli): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $cluster = $this->cluster->fresh();
        $password = $cluster->auth_password_encrypted;
        $sentinelPassword = $cluster->sentinel_password_encrypted;

        $nodes = $cluster->nodes;

        if ($nodes->isEmpty()) {
            return;
        }

        $lastError = null;
        $statusData = [];

        // Try to get sentinel status from any node
        foreach ($nodes as $node) {
            try {
                $mastersResult = $redisCli->getSentinelMasters($node, $sentinelPassword);

                if ($mastersResult['success'] && ! empty($mastersResult['output'])) {
                    // Get replication info from each node
                    $nodeStatuses = [];
                    foreach ($nodes as $n) {
                        try {
                            // Get full INFO for rich health data
                            $info = $redisCli->getInfo($n, null, $password);
                            if ($info['success']) {
                                $parsed = $this->parseInfoOutput($info['output']);
                                $role = $parsed['role'] ?? 'unknown';
                                $nodeStatuses[$n->id] = [
                                    'role' => $role,
                                    'connected' => true,
                                    'replication' => $parsed,
                                ];

                                // Update node role and status
                                // If this is the only node in the cluster, it's always master
                                $resolvedRole = ($nodes->count() === 1 || $role === 'master') ? 'master' : 'replica';
                                $n->update([
                                    'role' => $resolvedRole,
                                    'status' => 'online',
                                    'last_health_json' => $parsed,
                                    'last_checked_at' => now(),
                                ]);
                            } else {
                                $nodeStatuses[$n->id] = ['connected' => false];
                                $n->update(['status' => 'error', 'last_checked_at' => now()]);
                            }
                        } catch (\Throwable $e) {
                            $nodeStatuses[$n->id] = ['connected' => false, 'error' => $e->getMessage()];
                            $n->update(['status' => 'unreachable', 'last_checked_at' => now()]);
                        }
                    }

                    $statusData = [
                        'sentinel_source' => $node->name,
                        'nodes' => $nodeStatuses,
                    ];

                    // Derive cluster status
                    $onlineCount = collect($nodeStatuses)->where('connected', true)->count();
                    $totalCount = count($nodeStatuses);
                    $hasMaster = $nodes->count() === 1 || collect($nodeStatuses)->contains(fn ($s) => ($s['role'] ?? '') === 'master');

                    $clusterStatus = match (true) {
                        $onlineCount === 0 => 'offline',
                        ! $hasMaster => 'degraded',
                        $onlineCount < $totalCount => 'degraded',
                        default => 'online',
                    };

                    $cluster->update([
                        'status' => $clusterStatus,
                        'last_status_json' => $statusData,
                        'last_checked_at' => now(),
                    ]);

                    Cache::put("redis_cluster_status_{$cluster->id}", $statusData, now()->addMinutes(10));
                    Cache::forget("redis_cluster_refreshing_{$cluster->id}");

                    return;
                }

                $lastError = 'Sentinel returned empty response';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        // All nodes failed — still clear the refreshing flag
        Cache::forget("redis_cluster_refreshing_{$cluster->id}");

        Log::warning("RefreshRedisStatusJob: all nodes failed for redis cluster {$cluster->id}: {$lastError}");
    }

    protected function parseInfoOutput(string $output): array
    {
        $data = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $data[trim($key)] = trim($value);
            }
        }

        return $data;
    }
}
