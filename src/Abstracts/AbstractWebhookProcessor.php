<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Abstracts;

use Exception;
use Illuminate\Cache\TaggedCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\{Cache, Event, Log};
use OpenFGA\Laravel\Events\WebhookReceived;

use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Abstract base class for webhook processors.
 *
 * This abstract class provides the core functionality for processing webhooks
 * while allowing concrete implementations to be final.
 *
 * @internal
 */
abstract class AbstractWebhookProcessor
{
    protected readonly CacheRepository $cache;

    public function __construct(?CacheRepository $cache = null)
    {
        $this->cache = $cache ?? Cache::store();
    }

    /**
     * Process a webhook payload.
     *
     * @param array<string, mixed> $payload
     */
    public function process(array $payload): void
    {
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;

        if (! is_string($type) || ! is_array($data)) {
            Log::warning('Invalid webhook payload received', $payload);

            return;
        }

        /** @var array<string, mixed> $data */
        Event::dispatch(new WebhookReceived($type, $data));

        match ($type) {
            'authorization_model.write' => $this->handleAuthorizationModelWrite($data),
            'tuple.write' => $this->handleTupleWrite($data),
            'tuple.delete' => $this->handleTupleDelete($data),
            default => Log::info('Unknown webhook type received', ['type' => $type, 'data' => $data]),
        };
    }

    /**
     * Handle authorization model write webhook.
     *
     * @param array<string, mixed> $data
     */
    protected function handleAuthorizationModelWrite(array $data): void
    {
        if (! (bool) config('openfga.cache.enabled', false)) {
            return;
        }

        try {
            if ($this->cache instanceof TaggedCache) {
                $this->cache->tags(['openfga', 'authorization_models'])->flush();
                Log::info('Authorization model cache invalidated due to webhook');
            } else {
                Log::info('Cache store does not support tags, skipping cache invalidation');
            }
        } catch (Exception $exception) {
            Log::error('Failed to invalidate authorization model cache', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Handle tuple change (write or delete).
     *
     * @param array<string, mixed> $data
     * @param string               $operation
     */
    protected function handleTupleChange(array $data, string $operation): void
    {
        if (! (bool) config('openfga.cache.enabled', false)) {
            return;
        }

        $tuples = $data['tuples'] ?? [];

        if (! is_array($tuples)) {
            Log::warning('Invalid tuple data in webhook', $data);

            return;
        }

        $cacheKeys = [];

        foreach ($tuples as $tuple) {
            if (! is_array($tuple)) {
                continue;
            }

            $user = $tuple['user'] ?? null;
            $relation = $tuple['relation'] ?? null;
            $object = $tuple['object'] ?? null;

            if (! is_string($user)) {
                continue;
            }

            if (! is_string($relation)) {
                continue;
            }

            if (! is_string($object)) {
                continue;
            }

            $cacheKeys[] = sprintf('openfga.check.%s.%s.%s', $user, $relation, $object);
        }

        if ([] === $cacheKeys) {
            return;
        }

        try {
            if ($this->cache instanceof TaggedCache) {
                $this->cache->tags(['openfga', 'checks'])->flush();
                Log::info('Check cache invalidated due to webhook', [
                    'operation' => $operation,
                    'tuple_count' => count($tuples),
                ]);
            } else {
                foreach ($cacheKeys as $cacheKey) {
                    $this->cache->forget($cacheKey);
                }
                Log::info('Cache keys invalidated due to webhook', [
                    'operation' => $operation,
                    'keys' => $cacheKeys,
                ]);
            }
        } catch (Exception $exception) {
            Log::error('Failed to invalidate tuple cache', [
                'operation' => $operation,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Handle tuple delete webhook.
     *
     * @param array<string, mixed> $data
     */
    protected function handleTupleDelete(array $data): void
    {
        $this->handleTupleChange($data, 'delete');
    }

    /**
     * Handle tuple write webhook.
     *
     * @param array<string, mixed> $data
     */
    protected function handleTupleWrite(array $data): void
    {
        $this->handleTupleChange($data, 'write');
    }
}
