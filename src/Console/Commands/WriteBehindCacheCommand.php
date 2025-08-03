<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Cache\WriteBehindCache;

use function array_slice;
use function is_string;
use function sprintf;

final class WriteBehindCacheCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage write-behind cache';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:write-behind
                            {action : The action to perform (status, flush, clear)}';

    /**
     * Execute the console command.
     *
     * @param WriteBehindCache $cache
     */
    public function handle(WriteBehindCache $cache): int
    {
        if (true !== config('openfga.cache.write_behind.enabled')) {
            $this->error('Write-behind cache is not enabled.');
            $this->comment('Enable it by setting OPENFGA_WRITE_BEHIND_ENABLED=true');

            return self::FAILURE;
        }

        $action = $this->argument('action');

        if (! is_string($action)) {
            $this->error('Invalid action provided.');

            return self::FAILURE;
        }

        return match ($action) {
            'status' => $this->showStatus($cache),
            'flush' => $this->flushCache($cache),
            'clear' => $this->clearCache($cache),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Clear the cache without flushing.
     *
     * @param WriteBehindCache $cache
     */
    private function clearCache(WriteBehindCache $cache): int
    {
        $pending = $cache->getPendingCount();

        if (0 === $pending['total']) {
            $this->info('No pending operations to clear.');

            return self::SUCCESS;
        }

        if (! $this->confirm(sprintf('This will discard %d pending operations. Are you sure?', $pending['total']))) {
            return self::SUCCESS;
        }

        $cache->clear();
        $this->info('✅ Cache cleared. Pending operations discarded.');

        return self::SUCCESS;
    }

    /**
     * Flush the cache.
     *
     * @param WriteBehindCache $cache
     *
     * @throws ClientThrowable
     */
    private function flushCache(WriteBehindCache $cache): int
    {
        $pending = $cache->getPendingCount();

        if (0 === $pending['total']) {
            $this->info('No pending operations to flush.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Flushing %d pending operations...', $pending['total']));

        try {
            $stats = $cache->flush();

            $this->info('✅ Flush completed successfully!');
            $this->table(
                ['Operation', 'Count'],
                [
                    ['Writes', $stats['writes']],
                    ['Deletes', $stats['deletes']],
                ],
            );

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Flush failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Handle invalid action.
     *
     * @param string $action
     */
    private function invalidAction(string $action): int
    {
        $this->error('Invalid action: ' . $action);
        $this->comment('Valid actions are: status, flush, clear');

        return self::FAILURE;
    }

    /**
     * Show cache status.
     *
     * @param WriteBehindCache $cache
     */
    private function showStatus(WriteBehindCache $cache): int
    {
        $pending = $cache->getPendingCount();
        $operations = $cache->getPendingOperations();

        /** @var mixed $batchSize */
        $batchSize = config('openfga.cache.write_behind.batch_size', 100);

        /** @var mixed $flushInterval */
        $flushInterval = config('openfga.cache.write_behind.flush_interval', 5);

        $this->info('Write-Behind Cache Status:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Pending Writes', (string) $pending['writes']],
                ['Pending Deletes', (string) $pending['deletes']],
                ['Total Operations', (string) $pending['total']],
                ['Batch Size', (string) (is_numeric($batchSize) ? (int) $batchSize : 100)],
                ['Flush Interval', (string) (is_numeric($flushInterval) ? (int) $flushInterval : 5) . ' seconds'],
            ],
        );

        if (0 < $pending['total']) {
            $this->newLine();
            $this->comment('Recent operations:');

            $recent = array_slice($operations['writes'], -5, 5, true);

            foreach ($recent as $op) {
                $this->line(sprintf(
                    '  WRITE: %s → %s → %s (queued %s ago)',
                    $op['user'],
                    $op['relation'],
                    $op['object'],
                    now()->diffForHumans(now()->timestamp($op['timestamp'])),
                ));
            }

            $recent = array_slice($operations['deletes'], -5, 5, true);

            foreach ($recent as $op) {
                $this->line(sprintf(
                    '  DELETE: %s → %s → %s (queued %s ago)',
                    $op['user'],
                    $op['relation'],
                    $op['object'],
                    now()->diffForHumans(now()->timestamp($op['timestamp'])),
                ));
            }
        }

        return self::SUCCESS;
    }
}
