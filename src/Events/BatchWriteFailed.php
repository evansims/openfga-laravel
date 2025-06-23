<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Event fired when a batch write operation fails.
 */
class BatchWriteFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param array<array{user: string, relation: string, object: string}> $writes
     * @param array<array{user: string, relation: string, object: string}> $deletes
     * @param string|null $connection The connection used
     * @param Throwable $exception The exception that caused the failure
     * @param array<string, mixed> $options Additional options
     */
    public function __construct(
        public readonly array $writes,
        public readonly array $deletes,
        public readonly ?string $connection,
        public readonly Throwable $exception,
        public readonly array $options = []
    ) {
    }

    /**
     * Get the total number of operations that failed.
     */
    public function getTotalOperations(): int
    {
        return count($this->writes) + count($this->deletes);
    }

    /**
     * Get a summary of the failed batch operation.
     */
    public function getSummary(): array
    {
        return [
            'writes' => count($this->writes),
            'deletes' => count($this->deletes),
            'total' => $this->getTotalOperations(),
            'connection' => $this->connection,
            'error' => $this->exception->getMessage(),
            'exception_class' => get_class($this->exception),
        ];
    }
}