<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Webhooks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\WebhookReceived;

use function is_array;
use function is_string;

/**
 * Processes incoming webhooks from OpenFGA.
 *
 * This service handles the logic of parsing webhook payloads and
 * taking appropriate actions such as invalidating caches and
 * dispatching events.
 *
 * @internal
 * @psalm-suppress ClassMustBeFinal
 */
class WebhookProcessor
{
    private CacheRepository $cache;

    public function __construct(?CacheRepository $cache = null)
    {
        $this->cache = $cache ?? Cache::store();
    }

    /**
     * Process an incoming webhook payload.
     *
     * @param array<string, mixed> $payload
     * @return void
     */
    public function process(array $payload): void
    {
        /** @var string $type */
        $type = $payload['type'] ?? '';
        /** @var array<string, mixed> $data */
        $data = $payload['data'] ?? [];

        Log::info('Processing OpenFGA webhook', [
            'type' => $type,
            'data' => $data,
        ]);

        // Dispatch a general webhook received event
        Event::dispatch(new WebhookReceived($type, $data));

        // Handle specific webhook types
        match ($type) {
            'authorization_model_write' => $this->handleAuthorizationModelWrite($data),
            'tuple_write' => $this->handleTupleWrite($data),
            'tuple_delete' => $this->handleTupleDelete($data),
            default => Log::warning('Unknown OpenFGA webhook type', ['type' => $type]),
        };
    }

    /**
     * Handle authorization model write webhook.
     *
     * @param array<string, mixed> $data
     * @return void
     *
     * @psalm-suppress UnusedParam
     */
    private function handleAuthorizationModelWrite(array $data): void
    {
        // Clear all caches when the authorization model changes
        /** @var bool $cacheEnabled */
        $cacheEnabled = config('openfga.cache.enabled');
        if ($cacheEnabled) {
            /** @var string $prefix */
            $prefix = config('openfga.cache.prefix', 'openfga');
            
            // If the cache store supports tags, clear by tag
            if (method_exists($this->cache, 'tags')) {
                /** @var \Illuminate\Cache\TaggedCache $taggedCache */
                $taggedCache = $this->cache->tags([$prefix]);
                $taggedCache->flush();
                Log::info('Cleared OpenFGA cache due to authorization model change');
            } else {
                // Otherwise, we need to clear the entire cache (less ideal)
                Log::warning('Cache store does not support tags, unable to selectively clear OpenFGA cache');
            }
        }
    }

    /**
     * Handle tuple write webhook.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function handleTupleWrite(array $data): void
    {
        $this->invalidateTupleCache($data);
    }

    /**
     * Handle tuple delete webhook.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function handleTupleDelete(array $data): void
    {
        $this->invalidateTupleCache($data);
    }

    /**
     * Invalidate cache for a specific tuple.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    private function invalidateTupleCache(array $data): void
    {
        /** @var bool $cacheEnabled */
        $cacheEnabled = config('openfga.cache.enabled');
        if (!$cacheEnabled) {
            return;
        }

        $user = $data['user'] ?? null;
        $relation = $data['relation'] ?? null;
        $object = $data['object'] ?? null;

        if (!is_string($user) || !is_string($relation) || !is_string($object)) {
            Log::warning('Invalid tuple data in webhook', ['data' => $data]);
            return;
        }

        /** @var string $prefix */
        $prefix = config('openfga.cache.prefix', 'openfga');
        
        // Build cache keys to invalidate
        $cacheKeys = [
            // Direct check cache
            "{$prefix}:check:{$user}:{$relation}:{$object}",
            // List objects cache for user
            "{$prefix}:list_objects:{$user}:{$relation}:*",
            // List users cache for object
            "{$prefix}:list_users:*:{$relation}:{$object}",
            // Expand cache for object
            "{$prefix}:expand:{$object}:{$relation}",
        ];

        foreach ($cacheKeys as $pattern) {
            if (str_contains($pattern, '*')) {
                // If the cache supports pattern deletion
                $this->deleteByPattern($pattern);
            } else {
                $this->cache->forget($pattern);
            }
        }

        Log::info('Invalidated cache for tuple', [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
        ]);
    }

    /**
     * Delete cache entries by pattern.
     *
     * @param string $pattern
     * @return void
     */
    private function deleteByPattern(string $pattern): void
    {
        // This is a simplified implementation
        // In production, you might want to use Redis SCAN or similar
        Log::info('Would delete cache by pattern', ['pattern' => $pattern]);
    }
}