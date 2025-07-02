<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Job to write a single tuple to OpenFGA asynchronously.
 */
final class WriteTupleToFgaJob implements ShouldQueue
{
    use Dispatchable;

    use InteractsWithQueue;

    use Queueable;

    use SerializesModels;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param string  $user
     * @param string  $relation
     * @param string  $object
     * @param string  $operation
     * @param ?string $openfgaConnection
     */
    public function __construct(
        public readonly string $user,
        public readonly string $relation,
        public readonly string $object,
        public readonly string $operation = 'write',
        public readonly ?string $openfgaConnection = null,
    ) {
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoffArray(): array
    {
        return [10, 30, 60];
    }

    /**
     * Execute the job.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        try {
            /** @var OpenFgaManager $manager */
            $manager = OpenFga::getFacadeRoot();

            if (null !== $this->openfgaConnection) {
                $manager->setConnection($this->openfgaConnection);
            }

            if ('write' === $this->operation) {
                $result = $manager->grant($this->user, $this->relation, $this->object);

                if (! $result) {
                    throw new RuntimeException(sprintf('Failed to grant permission: %s %s %s', $this->user, $this->relation, $this->object));
                }

                Log::debug('Successfully wrote tuple to OpenFGA', [
                    'user' => $this->user,
                    'relation' => $this->relation,
                    'object' => $this->object,
                    'connection' => $this->openfgaConnection,
                ]);
            } elseif ('delete' === $this->operation) {
                $result = $manager->revoke($this->user, $this->relation, $this->object);

                if (! $result) {
                    throw new RuntimeException(sprintf('Failed to revoke permission: %s %s %s', $this->user, $this->relation, $this->object));
                }

                Log::debug('Successfully deleted tuple from OpenFGA', [
                    'user' => $this->user,
                    'relation' => $this->relation,
                    'object' => $this->object,
                    'connection' => $this->openfgaConnection,
                ]);
            }
        } catch (Throwable $throwable) {
            Log::error('Failed to write tuple to OpenFGA', [
                'user' => $this->user,
                'relation' => $this->relation,
                'object' => $this->object,
                'operation' => $this->operation,
                'connection' => $this->openfgaConnection,
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'openfga',
            'openfga:' . $this->operation,
            'openfga:object:' . $this->object,
        ];
    }
}
