<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Cache\CacheWarmer;
use OpenFGA\Laravel\OpenFgaManager;

use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Pre-loads frequently accessed permissions into cache for optimal performance.
 *
 * This command warms the OpenFGA cache by pre-fetching permissions based on
 * specified criteria or recent activity patterns. It supports bulk warming
 * for specific users, relations, and objects, or intelligent warming based
 * on historical access patterns. Use this to ensure critical permissions are
 * cached before peak usage periods or after cache clearing operations.
 */
final class WarmCacheCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm the OpenFGA permission cache';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:cache:warm
                            {--user=* : Specific users to warm cache for}
                            {--relation=* : Relations to check}
                            {--object=* : Objects to check}
                            {--type= : Object type for discovery}
                            {--activity : Warm based on recent activity}
                            {--limit=1000 : Maximum entries to warm}
                            {--connection= : The connection to use}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     * @param CacheWarmer    $warmer
     */
    public function handle(OpenFgaManager $manager, CacheWarmer $warmer): int
    {
        $cacheEnabled = config('openfga.cache.enabled');

        if (true !== $cacheEnabled) {
            $this->error('Cache is not enabled. Enable it in config/openfga.php');

            return 1;
        }

        $connectionOption = $this->option('connection');

        if (is_string($connectionOption)) {
            $manager->setDefaultConnection($connectionOption);
        }

        $this->info('Warming OpenFGA cache...');

        $activityOption = $this->option('activity');

        if (true === $activityOption) {
            return $this->warmFromActivity($warmer);
        }

        $usersOption = $this->option('user');
        $relationsOption = $this->option('relation');
        $objectsOption = $this->option('object');
        $typeOption = $this->option('type');

        // Ensure options are arrays of strings
        $users = [];

        if (is_array($usersOption)) {
            /** @var mixed $userOption */
            foreach ($usersOption as $userOption) {
                if (is_string($userOption)) {
                    $users[] = $userOption;
                }
            }
        }

        $relations = [];

        if (is_array($relationsOption)) {
            /** @var mixed $relationOption */
            foreach ($relationsOption as $relationOption) {
                if (is_string($relationOption)) {
                    $relations[] = $relationOption;
                }
            }
        }

        $objects = [];

        if (is_array($objectsOption)) {
            /** @var mixed $objectOption */
            foreach ($objectsOption as $objectOption) {
                if (is_string($objectOption)) {
                    $objects[] = $objectOption;
                }
            }
        }

        $type = is_string($typeOption) ? $typeOption : null;

        // Validate inputs
        if ([] === $users && [] === $objects && null === $type) {
            $this->error('Please specify users, objects, or an object type to warm cache for.');

            return 1;
        }

        if ([] === $relations) {
            $this->error('Please specify at least one relation to check.');

            return 1;
        }

        // If type is specified, discover objects
        if (null !== $type && [] !== $users) {
            return $this->warmByType($manager, $warmer, $users, $relations, $type);
        }

        // Warm specific combinations
        if ([] !== $users && [] !== $objects) {
            return $this->warmSpecific($warmer, $users, $relations, $objects);
        }

        $this->error('Invalid combination of options.');

        return 1;
    }

    /**
     * Warm cache by discovering objects of a type.
     *
     * @param OpenFgaManager $manager
     * @param CacheWarmer    $warmer
     * @param array<string>  $users
     * @param array<string>  $relations
     * @param string         $type
     */
    private function warmByType(
        OpenFgaManager $manager,
        CacheWarmer $warmer,
        array $users,
        array $relations,
        string $type,
    ): int {
        $this->info(sprintf('Discovering %s objects for users...', $type));

        $totalWarmed = 0;
        $progressBar = $this->output->createProgressBar(count($users));

        foreach ($users as $user) {
            $objects = [];

            // Discover objects for each relation
            foreach ($relations as $relation) {
                $discovered = $manager->listObjects($user, $relation, $type);
                $objects = array_merge($objects, $discovered);
            }

            $objects = array_unique($objects);

            if ([] !== $objects) {
                $warmed = $warmer->warmForUser($user, $relations, $objects);
                $totalWarmed += $warmed;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info(sprintf('✅ Warmed %d cache entries.', $totalWarmed));

        return 0;
    }

    /**
     * Warm cache from recent activity.
     *
     * @param CacheWarmer $warmer
     */
    private function warmFromActivity(CacheWarmer $warmer): int
    {
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 1000;
        $this->info(sprintf('Warming cache based on recent activity (limit: %d)...', $limit));

        $warmed = $warmer->warmFromActivity($limit);

        $this->info(sprintf('✅ Warmed %d cache entries from activity patterns.', $warmed));

        return 0;
    }

    /**
     * Warm cache for specific users, relations, and objects.
     *
     * @param CacheWarmer   $warmer
     * @param array<string> $users
     * @param array<string> $relations
     * @param array<string> $objects
     */
    private function warmSpecific(
        CacheWarmer $warmer,
        array $users,
        array $relations,
        array $objects,
    ): int {
        $total = count($users) * count($relations) * count($objects);
        $this->info(sprintf('Warming cache for %d permission combinations...', $total));

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $warmed = $warmer->warmBatch($users, $relations, $objects);

        $progressBar->finish();
        $this->newLine();

        $this->info(sprintf('✅ Warmed %d cache entries.', $warmed));

        return 0;
    }
}
