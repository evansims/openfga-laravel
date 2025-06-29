<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Cache;

use Illuminate\Cache\Repository;

/**
 * Test cache repository that tracks operations.
 */
final class TestCacheRepository extends Repository
{
    private array $lastPut = [];

    private int $putCount = 0;

    public function getLastPut(): array
    {
        return $this->lastPut;
    }

    public function getPutCount(): int
    {
        return $this->putCount;
    }

    public function increment($key, $value = 1)
    {
        $current = $this->get($key, 0);
        $this->put($key, $current + $value);

        return $current + $value;
    }

    public function put($key, $value, $ttl = null): bool
    {
        $this->putCount++;
        $this->lastPut = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

        return parent::put($key, $value, $ttl);
    }
}
