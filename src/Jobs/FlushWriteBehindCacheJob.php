<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Cache\WriteBehindCache;
use Throwable;

final class FlushWriteBehindCacheJob implements ShouldQueue
{
    use Dispatchable;

    use InteractsWithQueue;

    use Queueable;

    use SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable $exception
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('Write-behind cache flush permanently failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Here you might want to:
        // 1. Send an alert
        // 2. Write to a dead letter queue
        // 3. Attempt manual recovery
    }

    /**
     * Execute the job.
     *
     * @param WriteBehindCache $cache
     */
    public function handle(WriteBehindCache $cache): void
    {
        $startTime = microtime(true);

        try {
            $stats = $cache->flush();

            $duration = microtime(true) - $startTime;

            if (0 < $stats['writes'] || 0 < $stats['deletes']) {
                Log::info('Write-behind cache flushed', [
                    'writes' => $stats['writes'],
                    'deletes' => $stats['deletes'],
                    'duration_ms' => round($duration * 1000, 2),
                ]);
            }
        } catch (Exception $exception) {
            Log::error('Write-behind cache flush failed', [
                'error' => $exception->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Rethrow to trigger retry
            throw $exception;
        }
    }
}
