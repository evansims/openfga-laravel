<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

use function count;

/**
 * Event fired when a batch write operation completes successfully.
 */
final class BatchWriteCompleted
{
    use Dispatchable;

    use InteractsWithSockets;

    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param array<array{user: string, relation: string, object: string}> $writes
     * @param array<array{user: string, relation: string, object: string}> $deletes
     * @param string|null                                                  $connection The connection used
     * @param float                                                        $duration   The duration of the operation in seconds
     * @param array<string, mixed>                                         $options    Additional options
     */
    public function __construct(
        public readonly array $writes,
        public readonly array $deletes,
        public readonly ?string $connection = null,
        public readonly float $duration = 0.0,
        public readonly array $options = [],
    ) {
    }

    /**
     * Get a summary of the batch operation.
     *
     * @return array{writes: int, deletes: int, total: int, duration: float, connection: string|null}
     */
    public function getSummary(): array
    {
        return [
            'writes' => count($this->writes),
            'deletes' => count($this->deletes),
            'total' => $this->getTotalOperations(),
            'duration' => $this->duration,
            'connection' => $this->connection,
        ];
    }

    /**
     * Get the total number of operations.
     */
    public function getTotalOperations(): int
    {
        return count($this->writes) + count($this->deletes);
    }
}
