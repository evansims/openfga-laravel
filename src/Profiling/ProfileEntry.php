<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Profiling;

use Carbon\Carbon;

/**
 * Individual profiling entry for tracking OpenFGA operation performance.
 *
 * This class represents a single profiled operation, capturing detailed timing
 * information, parameters, cache status, and metadata. Each entry tracks the
 * complete lifecycle of an authorization operation from start to finish,
 * including success/failure status and any errors. Use these entries to
 * analyze specific operations and identify performance optimization opportunities.
 *
 * @internal
 */
final class ProfileEntry
{
    private readonly float $startTime;

    private ?string $cacheStatus = null;

    private ?float $endTime = null;

    private ?string $error = null;

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    private ?bool $success = null;

    /**
     * @param array<string, mixed> $parameters
     * @param string               $operation
     */
    public function __construct(private readonly string $operation, private readonly array $parameters = [])
    {
        $this->startTime = microtime(true);
    }

    public function addMetadata(string $key, float | int $value): self
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

        return ($this->endTime - $this->startTime) * 1000.0; // Convert to milliseconds
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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
