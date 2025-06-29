<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Cache\CacheWarmer;

use function is_string;
use function sprintf;

/**
 * Command to clear OpenFGA permission cache.
 */
final class ClearCacheCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Clear the OpenFGA permission cache';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:cache:clear
                            {--user= : Clear cache for specific user}
                            {--relation= : Clear cache for specific relation}
                            {--object= : Clear cache for specific object}
                            {--all : Clear all OpenFGA cache entries}';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (true !== config('openfga.cache.enabled')) {
            $this->error('Cache is not enabled. Enable it in config/openfga.php');

            return 1;
        }

        $user = $this->option('user');
        $user = is_string($user) ? $user : null;

        $relation = $this->option('relation');
        $relation = is_string($relation) ? $relation : null;

        $object = $this->option('object');
        $object = is_string($object) ? $object : null;

        $all = true === $this->option('all');

        if (! $all && null === $user && null === $relation && null === $object) {
            $this->error('Please specify what to clear or use --all flag.');

            return 1;
        }

        if ($all) {
            if (! $this->confirm('Are you sure you want to clear all OpenFGA cache entries?')) {
                return 0;
            }
            $user = null;
            $relation = null;
            $object = null;
        }

        $this->info('Clearing OpenFGA cache...');

        $warmer = app(CacheWarmer::class);

        $cleared = $warmer->invalidate($user, $relation, $object);

        if (0 < $cleared) {
            $this->info(sprintf('✅ Cleared %d cache entries.', $cleared));
        } else {
            $this->warn('No cache entries were cleared (cache store may not support pattern deletion).');
        }

        return 0;
    }
}
