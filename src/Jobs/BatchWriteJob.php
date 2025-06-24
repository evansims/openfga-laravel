<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Jobs;

use DateTimeImmutable;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Events\{BatchWriteCompleted, BatchWriteFailed};
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\TupleKey;
use Throwable;

use function count;
use function is_string;

/**
 * Queueable job for batch write operations.
 */
final class BatchWriteJob implements ShouldQueue
{
    use Dispatchable;

    use InteractsWithQueue;

    use Queueable;

    use SerializesModels;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * Indicate if the job should be encrypted.
     *
     * @var bool
     */
    public $shouldBeEncrypted = true;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param array<array{user: string, relation: string, object: string}> $writes
     * @param array<array{user: string, relation: string, object: string}> $deletes
     * @param string|null                                                  $openfgaConnection
     * @param array<string, mixed>                                         $options
     */
    public function __construct(
        private array $writes = [],
        private array $deletes = [],
        private ?string $openfgaConnection = null,
        private array $options = [],
    ) {
        // Set queue configuration
        /** @var mixed $queueEnabled */
        $queueEnabled = config('openfga.queue.enabled');

        if (true === $queueEnabled) {
            /** @var mixed $connection */
            $connection = config('openfga.queue.connection');

            /** @var mixed $queue */
            $queue = config('openfga.queue.queue');

            if (is_string($connection)) {
                $this->onConnection($connection);
            }

            if (is_string($queue)) {
                $this->onQueue($queue);
            }
        }
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
     * Handle a job failure.
     *
     * @param Throwable $exception
     */
    public function failed(Throwable $exception): void
    {
        // Dispatch failure event
        event(new BatchWriteFailed(
            $this->writes,
            $this->deletes,
            $this->openfgaConnection,
            $exception,
            $this->options,
        ));
    }

    /**
     * Execute the job.
     *
     * @param OpenFgaManager $manager
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function handle(OpenFgaManager $manager): void
    {
        $startTime = microtime(true);
        $manager->write(
            writes: [] !== $this->writes ? new TupleKeys(
                array_map(
                    static fn ($write): TupleKey => new TupleKey(
                        user: $write['user'],
                        relation: $write['relation'],
                        object: $write['object'],
                    ),
                    $this->writes,
                ),
            ) : null,
            deletes: [] !== $this->deletes ? new TupleKeys(
                array_map(
                    static fn ($delete): TupleKey => new TupleKey(
                        user: $delete['user'],
                        relation: $delete['relation'],
                        object: $delete['object'],
                    ),
                    $this->deletes,
                ),
            ) : null,
            connection: $this->openfgaConnection,
        );
        $duration = microtime(true) - $startTime;
        // Dispatch completion event
        event(new BatchWriteCompleted(
            $this->writes,
            $this->deletes,
            $this->openfgaConnection,
            $duration,
            $this->options,
        ));
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return new DateTimeImmutable(now()->addMinutes(15)->toDateTimeString());
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        $tags = ['openfga', 'batch-write'];

        if (null !== $this->openfgaConnection) {
            $tags[] = 'connection:' . $this->openfgaConnection;
        }

        $tags[] = 'writes:' . count($this->writes);
        $tags[] = 'deletes:' . count($this->deletes);

        return $tags;
    }
}
