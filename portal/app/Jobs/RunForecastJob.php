<?php

namespace App\Jobs;

use App\Models\ComputeRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class RunForecastJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $computeRunId)
    {
    }

    public function handle(): void
    {
        $run = ComputeRun::find($this->computeRunId);

        if (! $run || in_array($run->status, ['succeeded', 'failed'], true)) {
            return;
        }

        $url = config('services.modal.train_url');
        $token = config('services.modal.train_token');

        if (! $url || ! $token) {
            $run->update([
                'status' => 'failed',
                'error' => 'Modal training is not configured (MODAL_TRAIN_URL / MODAL_TRAIN_TOKEN).',
                'finished_at' => now(),
            ]);

            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'error' => null,
        ]);

        try {
            $response = Http::timeout(30)->acceptJson()->post($url, [
                'token' => $token,
                'compute_run_id' => $run->id,
                'organization_id' => $run->organization_id,
                'store_id' => 1,
                'periods' => 14,
                'engine' => 'auto',
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => 'Could not reach Modal: '.$e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        if (! $response->successful()) {
            $run->update([
                'status' => 'failed',
                'error' => 'Modal trigger failed: '.$response->body(),
                'finished_at' => now(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        ComputeRun::find($this->computeRunId)?->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
