<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OpenFGA\Laravel\Events\BatchWriteCompleted;
use OpenFGA\Laravel\Events\BatchWriteFailed;
use OpenFGA\Laravel\OpenFgaManager;
use Throwable;

/**
 * Queueable job for batch write operations.
 */
class BatchWriteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Indicate if the job should be encrypted.
     *
     * @var bool
     */
    public $shouldBeEncrypted = true;

    /**
     * Create a new job instance.
     *
     * @param array<array{user: string, relation: string, object: string}> $writes
     * @param array<array{user: string, relation: string, object: string}> $deletes
     * @param string|null $openfgaConnection
     * @param array<string, mixed> $options
     */
    public function __construct(
        private array $writes = [],
        private array $deletes = [],
        private ?string $openfgaConnection = null,
        private array $options = []
    ) {
        // Set queue configuration
        if (config('openfga.queue.enabled')) {
            $this->onConnection(config('openfga.queue.connection'))
                ->onQueue(config('openfga.queue.queue'));
        }
    }

    /**
     * Execute the job.
     */
    public function handle(OpenFgaManager $manager): void
    {
        $startTime = microtime(true);
        
        try {
            // Perform the batch write operation
            $manager->connection($this->openfgaConnection)
                ->writeBatch($this->writes, $this->deletes);

            $duration = microtime(true) - $startTime;

            // Dispatch completion event
            event(new BatchWriteCompleted(
                $this->writes,
                $this->deletes,
                $this->openfgaConnection,
                $duration,
                $this->options
            ));
        } catch (Throwable $exception) {
            // Re-throw to trigger failed() method
            throw $exception;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        // Dispatch failure event
        event(new BatchWriteFailed(
            $this->writes,
            $this->deletes,
            $this->openfgaConnection,
            $exception,
            $this->options
        ));
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        $tags = ['openfga', 'batch-write'];

        if ($this->openfgaConnection) {
            $tags[] = 'connection:' . $this->openfgaConnection;
        }

        $tags[] = 'writes:' . count($this->writes);
        $tags[] = 'deletes:' . count($this->deletes);

        return $tags;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(15);
    }
}