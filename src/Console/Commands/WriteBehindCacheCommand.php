<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Cache\WriteBehindCache;

class WriteBehindCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:write-behind
                            {action : The action to perform (status, flush, clear)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage write-behind cache';

    /**
     * Execute the console command.
     */
    public function handle(WriteBehindCache $cache): int
    {
        if (! config('openfga.cache.write_behind.enabled')) {
            $this->error('Write-behind cache is not enabled.');
            $this->comment('Enable it by setting OPENFGA_WRITE_BEHIND_ENABLED=true');
            return self::FAILURE;
        }

        $action = $this->argument('action');

        return match ($action) {
            'status' => $this->showStatus($cache),
            'flush' => $this->flushCache($cache),
            'clear' => $this->clearCache($cache),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show cache status
     */
    private function showStatus(WriteBehindCache $cache): int
    {
        $pending = $cache->getPendingCount();
        $operations = $cache->getPendingOperations();

        $this->info('Write-Behind Cache Status:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Pending Writes', $pending['writes']],
                ['Pending Deletes', $pending['deletes']],
                ['Total Operations', $pending['total']],
                ['Batch Size', config('openfga.cache.write_behind.batch_size', 100)],
                ['Flush Interval', config('openfga.cache.write_behind.flush_interval', 5) . ' seconds'],
            ]
        );

        if ($pending['total'] > 0) {
            $this->newLine();
            $this->comment('Recent operations:');
            
            $recent = array_slice($operations['writes'], -5, 5, true);
            foreach ($recent as $op) {
                $this->line(sprintf(
                    "  WRITE: %s → %s → %s (queued %s ago)",
                    $op['user'],
                    $op['relation'],
                    $op['object'],
                    now()->diffForHumans($op['timestamp'], true)
                ));
            }

            $recent = array_slice($operations['deletes'], -5, 5, true);
            foreach ($recent as $op) {
                $this->line(sprintf(
                    "  DELETE: %s → %s → %s (queued %s ago)",
                    $op['user'],
                    $op['relation'],
                    $op['object'],
                    now()->diffForHumans($op['timestamp'], true)
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Flush the cache
     */
    private function flushCache(WriteBehindCache $cache): int
    {
        $pending = $cache->getPendingCount();

        if ($pending['total'] === 0) {
            $this->info('No pending operations to flush.');
            return self::SUCCESS;
        }

        $this->info("Flushing {$pending['total']} pending operations...");

        try {
            $stats = $cache->flush();
            
            $this->info('✅ Flush completed successfully!');
            $this->table(
                ['Operation', 'Count'],
                [
                    ['Writes', $stats['writes']],
                    ['Deletes', $stats['deletes']],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Flush failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Clear the cache without flushing
     */
    private function clearCache(WriteBehindCache $cache): int
    {
        $pending = $cache->getPendingCount();

        if ($pending['total'] === 0) {
            $this->info('No pending operations to clear.');
            return self::SUCCESS;
        }

        if (! $this->confirm("This will discard {$pending['total']} pending operations. Are you sure?")) {
            return self::SUCCESS;
        }

        $cache->clear();
        $this->info('✅ Cache cleared. Pending operations discarded.');

        return self::SUCCESS;
    }

    /**
     * Handle invalid action
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->comment('Valid actions are: status, flush, clear');
        return self::FAILURE;
    }
}