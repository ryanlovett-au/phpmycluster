<?php

namespace App\Jobs;

use App\Models\MysqlNode;
use App\Services\MysqlProvisionService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshRouterStatusJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(
        public MysqlNode $node,
    ) {}

    public function handle(MysqlProvisionService $provisionService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $result = $provisionService->getRouterStatus($this->node);
            $this->node->update([
                'status' => $result['running'] ? 'online' : 'offline',
                'last_checked_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->node->update([
                'status' => 'error',
                'last_checked_at' => now(),
            ]);
            Log::warning("RefreshRouterStatusJob: failed for node {$this->node->id}: {$e->getMessage()}");
        }
    }
}
