<?php

namespace App\Jobs\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Shared progress tracking for queued jobs.
 *
 * Jobs using this trait must implement:
 * - getCacheKey(): string — returns the cache key for progress tracking
 */
trait TracksProgress
{
    /**
     * Add a progress step to the cache.
     *
     * When a new step is added, any previous "running" steps
     * are automatically marked as "success" — they must have completed
     * if we've moved on to the next step.
     */
    protected function addStep(string $message, string $status = 'running'): void
    {
        $key = $this->getCacheKey();
        $progress = Cache::get($key, ['steps' => [], 'status' => 'running']);

        foreach ($progress['steps'] as &$step) {
            if ($step['status'] === 'running') {
                $step['status'] = 'success';
            }
        }
        unset($step);

        $progress['steps'][] = [
            'message' => $message,
            'status' => $status,
            'time' => now()->format('H:i:s'),
        ];

        Cache::put($key, $progress, now()->addHours(2));
    }

    /**
     * Set the overall provision status and clean up any lingering "running" steps.
     */
    protected function setStatus(string $status): void
    {
        $key = $this->getCacheKey();
        $progress = Cache::get($key, ['steps' => [], 'status' => 'running']);
        $progress['status'] = $status;

        $resolvedStatus = $status === 'complete' ? 'success' : 'error';
        foreach ($progress['steps'] as &$step) {
            if ($step['status'] === 'running') {
                $step['status'] = $resolvedStatus;
            }
        }

        Cache::put($key, $progress, now()->addHours(2));
    }
}
