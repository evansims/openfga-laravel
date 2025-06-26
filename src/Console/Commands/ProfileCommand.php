<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Profiling\{OpenFgaProfiler};

use function count;
use function sprintf;

final class ProfileCommand extends Command
{
    /**
     * @var string|null
     */
    protected $description = 'Display OpenFGA profiling information';

    protected $signature = 'openfga:profile
                            {--reset : Reset the profiler statistics}
                            {--enable : Enable profiling}
                            {--disable : Disable profiling}
                            {--slow : Show only slow queries}
                            {--json : Output as JSON}';

    public function handle(OpenFgaProfiler $profiler): int
    {
        if (true === $this->option('enable')) {
            $profiler->enable();
            $this->info('OpenFGA profiling enabled');

            return 0;
        }

        if (true === $this->option('disable')) {
            $profiler->disable();
            $this->info('OpenFGA profiling disabled');

            return 0;
        }

        if (true === $this->option('reset')) {
            $profiler->reset();
            $this->info('Profiling statistics reset');

            return 0;
        }

        if (true === $this->option('json')) {
            $encoded = json_encode($profiler->toArray(), JSON_PRETTY_PRINT);

            if (false !== $encoded) {
                $this->output->writeln($encoded);
            }

            return 0;
        }

        $this->displayProfileSummary($profiler);

        if (true === $this->option('slow')) {
            $this->displaySlowQueries($profiler);
        }

        return 0;
    }

    private function displayProfileSummary(OpenFgaProfiler $profiler): void
    {
        /** @var array{total_operations: int, total_time: float, slow_queries: int, operations: array<string, array{count: int, total_time: float, avg_time: float, min_time: float, max_time: float}>} $summary */
        $summary = $profiler->getSummary();

        $this->info('OpenFGA Profile Summary');
        $this->info('=======================');
        $this->line('');

        $this->line(sprintf('Total Operations: %d', $summary['total_operations']));
        $this->line(sprintf('Total Time: %.2f ms', $summary['total_time']));
        $this->line(sprintf('Slow Queries: %d', $summary['slow_queries']));
        $this->line('');

        if (0 === count($summary['operations'])) {
            $this->warn('No operations recorded');

            return;
        }

        $headers = ['Operation', 'Count', 'Total (ms)', 'Avg (ms)', 'Min (ms)', 'Max (ms)'];
        $rows = [];

        foreach ($summary['operations'] as $operation => $stats) {
            $rows[] = [
                $operation,
                $stats['count'],
                sprintf('%.2f', $stats['total_time']),
                sprintf('%.2f', $stats['avg_time']),
                sprintf('%.2f', $stats['min_time']),
                sprintf('%.2f', $stats['max_time']),
            ];
        }

        $this->table($headers, $rows);
    }

    private function displaySlowQueries(OpenFgaProfiler $profiler): void
    {
        $slowQueries = $profiler->getSlowQueries();

        if ($slowQueries->isEmpty()) {
            $this->info('No slow queries found');

            return;
        }

        /** @var mixed $threshold */
        $threshold = config('openfga.profiling.slow_query_threshold', 100);
        $this->warn(sprintf('Slow Queries (> %d ms)', is_numeric($threshold) ? (int) $threshold : 100));
        $this->line('');

        $headers = ['Operation', 'Duration (ms)', 'Parameters', 'Cache Status'];
        $rows = [];

        foreach ($slowQueries as $slowQuery) {
            $rows[] = [
                $slowQuery->getOperation(),
                sprintf('%.2f', $slowQuery->getDuration()),
                json_encode($slowQuery->getParameters()),
                $slowQuery->getCacheStatus() ?? 'N/A',
            ];
        }

        $this->table($headers, $rows);
    }
}
