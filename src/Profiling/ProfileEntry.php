<?php

declare(strict_types=1);

namespace OpenFga\Laravel\Profiling;

use Carbon\Carbon;

final class ProfileEntry
{
    private readonly ?float $startTime;

    private ?string $cacheStatus = null;

    private ?float $endTime = null;

    private ?string $error = null;

    private array $metadata = [];

    private ?bool $success = null;

    public function __construct(private readonly string $operation, private readonly array $parameters = [])
    {
        $this->startTime = microtime(true);
    }

    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function end(bool $success = true, ?string $error = null): self
    {
        $this->endTime = microtime(true);
        $this->success = $success;
        $this->error = $error;

        return $this;
    }

    public function getCacheStatus(): ?string
    {
        return $this->cacheStatus;
    }

    public function getDuration(): float
    {
        if (null === $this->endTime) {
            return microtime(true) - $this->startTime;
        }

        return ($this->endTime - $this->startTime) * 1000; // Convert to milliseconds
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function isSuccess(): ?bool
    {
        return $this->success;
    }

    public function setCacheStatus(string $status): self
    {
        $this->cacheStatus = $status;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'parameters' => $this->parameters,
            'started_at' => Carbon::createFromTimestamp($this->startTime)->toIso8601String(),
            'duration_ms' => $this->getDuration(),
            'success' => $this->success,
            'error' => $this->error,
            'cache_status' => $this->cacheStatus,
            'metadata' => $this->metadata,
        ];
    }
}
