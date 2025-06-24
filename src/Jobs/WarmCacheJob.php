<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use InvalidArgumentException;
use OpenFGA\Laravel\Cache\CacheWarmer;

use function count;
use function is_int;
use function is_string;

/**
 * Job to warm OpenFGA cache in the background.
 */
final class WarmCacheJob implements ShouldQueue
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
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param array<string>        $users
     * @param array<string>        $relations
     * @param array<string>        $objects
     * @param array<string, mixed> $options
     */
    public function __construct(
        private array $users = [],
        private array $relations = [],
        private array $objects = [],
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
     * Execute the job.
     *
     * @param CacheWarmer $warmer
     */
    public function handle(CacheWarmer $warmer): void
    {
        /** @var mixed $method */
        $method = $this->options['method'] ?? 'batch';

        match ($method) {
            'user' => $this->warmForUser($warmer),
            'activity' => $this->warmFromActivity($warmer),
            'related' => $this->warmRelated($warmer),
            'hierarchy' => $this->warmHierarchy($warmer),
            default => $this->warmBatch($warmer),
        };
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'openfga',
            'cache-warming',
            'users:' . count($this->users),
            'objects:' . count($this->objects),
        ];
    }

    /**
     * Warm batch of permissions.
     *
     * @param CacheWarmer $warmer
     */
    private function warmBatch(CacheWarmer $warmer): void
    {
        $warmer->warmBatch($this->users, $this->relations, $this->objects);
    }

    /**
     * Warm cache for a specific user.
     *
     * @param CacheWarmer $warmer
     */
    private function warmForUser(CacheWarmer $warmer): void
    {
        $user = $this->users[0] ?? throw new InvalidArgumentException('User required for user warming');
        $warmer->warmForUser($user, $this->relations, $this->objects);
    }

    /**
     * Warm cache from activity.
     *
     * @param CacheWarmer $warmer
     */
    private function warmFromActivity(CacheWarmer $warmer): void
    {
        /** @var mixed $limit */
        $limit = $this->options['limit'] ?? 1000;

        if (is_int($limit)) {
            $warmer->warmFromActivity($limit);
        } else {
            $warmer->warmFromActivity(1000);
        }
    }

    /**
     * Warm hierarchical permissions.
     *
     * @param CacheWarmer $warmer
     */
    private function warmHierarchy(CacheWarmer $warmer): void
    {
        $user = $this->users[0] ?? throw new InvalidArgumentException('User required for hierarchy warming');
        $object = $this->objects[0] ?? throw new InvalidArgumentException('Object required for hierarchy warming');
        $warmer->warmHierarchy($user, $object, $this->relations);
    }

    /**
     * Warm related objects.
     *
     * @param CacheWarmer $warmer
     */
    private function warmRelated(CacheWarmer $warmer): void
    {
        $user = $this->users[0] ?? throw new InvalidArgumentException('User required for related warming');
        $sourceObject = $this->objects[0] ?? throw new InvalidArgumentException('Source object required');
        $warmer->warmRelated($user, $sourceObject, $this->relations);
    }
}
