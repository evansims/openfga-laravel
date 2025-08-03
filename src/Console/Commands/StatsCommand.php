<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Monitoring\PerformanceMonitor;

use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Command to display OpenFGA performance statistics.
 */
final class StatsCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display OpenFGA performance statistics';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:stats
                            {--metric= : Specific metric to display (permission_checks, batch_writes, cache, performance)}
                            {--minutes=60 : Time window in minutes}
                            {--json : Output as JSON}
                            {--reset : Reset all statistics}';

    /**
     * Execute the console command.
     *
     * @param PerformanceMonitor $monitor
     */
    public function handle(PerformanceMonitor $monitor): int
    {
        $resetOption = $this->option('reset');

        if (true === $resetOption) {
            return $this->handleReset($monitor);
        }

        $metricOption = $this->option('metric');
        $metric = is_string($metricOption) ? $metricOption : null;

        $minutesOption = $this->option('minutes');
        $minutes = is_numeric($minutesOption) ? (int) $minutesOption : 60;

        $stats = $monitor->getStatistics($metric, $minutes);

        $jsonOption = $this->option('json');

        if (true === $jsonOption) {
            $encoded = json_encode($stats, JSON_PRETTY_PRINT);

            if (false !== $encoded) {
                $this->output->writeln($encoded);
            }
        } else {
            $this->displayStatistics($stats, $minutes);
        }

        return Command::SUCCESS;
    }

    /**
     * Display batch write statistics.
     *
     * @param array<string, mixed> $stats
     */
    private function displayBatchWriteStats(array $stats): void
    {
        $this->info('📝 Batch Writes:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Batches', number_format($this->toFloat($stats['count'] ?? 0))],
                ['Average Duration', $this->formatDuration($this->toFloat($stats['avg_duration'] ?? 0))],
                ['Min Duration', $this->formatDuration($this->toFloat($stats['min_duration'] ?? 0))],
                ['Max Duration', $this->formatDuration($this->toFloat($stats['max_duration'] ?? 0))],
            ],
        );
        $this->newLine();
    }

    /**
     * Display cache statistics.
     *
     * @param array<string, mixed> $stats
     */
    private function displayCacheStats(array $stats): void
    {
        $this->info('💾 Cache Performance:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Cache Hits', number_format($this->toFloat($stats['hits'] ?? 0))],
                ['Cache Misses', number_format($this->toFloat($stats['misses'] ?? 0))],
                ['Hit Rate', number_format($this->toFloat($stats['hit_rate'] ?? 0), 2) . '%'],
            ],
        );
        $this->newLine();
    }

    /**
     * Display overall performance statistics.
     *
     * @param array<string, mixed> $stats
     */
    private function displayPerformanceStats(array $stats): void
    {
        $this->info('⚡ Overall Performance:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Operations', number_format($this->toFloat($stats['total_operations'] ?? 0))],
                ['Average Response Time', $this->formatDuration($this->toFloat($stats['avg_response_time'] ?? 0))],
                ['Operations per Minute', number_format($this->toFloat($stats['operations_per_minute'] ?? 0), 2)],
            ],
        );
        $this->newLine();
    }

    /**
     * Display permission check statistics.
     *
     * @param array<string, mixed> $stats
     */
    private function displayPermissionCheckStats(array $stats): void
    {
        $this->info('📊 Permission Checks:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Checks', number_format($this->toFloat($stats['count'] ?? 0))],
                ['Average Duration', $this->formatDuration($this->toFloat($stats['avg_duration'] ?? 0))],
                ['Min Duration', $this->formatDuration($this->toFloat($stats['min_duration'] ?? 0))],
                ['Max Duration', $this->formatDuration($this->toFloat($stats['max_duration'] ?? 0))],
            ],
        );
        $this->newLine();
    }

    /**
     * Display statistics in a formatted way.
     *
     * @param array<string, array{count?: float|int, avg_duration?: float, min_duration?: float, max_duration?: float, hits?: float|int, misses?: float|int, hit_rate?: float, total_operations?: float|int, avg_response_time?: float, operations_per_minute?: float}> $stats
     * @param int                                                                                                                                                                                                                                                       $minutes
     */
    private function displayStatistics(array $stats, int $minutes): void
    {
        $this->info(sprintf('OpenFGA Performance Statistics (last %d minutes)', $minutes));
        $this->line('=' . str_repeat('=', 50));
        $this->newLine();

        if (isset($stats['permission_checks'])) {
            $this->displayPermissionCheckStats($stats['permission_checks']);
        }

        if (isset($stats['batch_writes'])) {
            $this->displayBatchWriteStats($stats['batch_writes']);
        }

        if (isset($stats['cache'])) {
            $this->displayCacheStats($stats['cache']);
        }

        if (isset($stats['performance'])) {
            $this->displayPerformanceStats($stats['performance']);
        }
    }

    /**
     * Format a duration value.
     *
     * @param float $seconds
     */
    private function formatDuration(float $seconds): string
    {
        $milliseconds = $seconds * 1000.0;

        if (1 > $milliseconds) {
            return number_format($milliseconds * 1000.0, 2) . 'μs';
        }

        if (1000 > $milliseconds) {
            return number_format($milliseconds, 2) . 'ms';
        }

        return number_format($seconds, 2) . 's';
    }

    /**
     * Handle resetting statistics.
     *
     * @param PerformanceMonitor $monitor
     */
    private function handleReset(PerformanceMonitor $monitor): int
    {
        if (! $this->confirm('Are you sure you want to reset all statistics?')) {
            return Command::SUCCESS;
        }

        $monitor->reset();

        $this->info('✅ All statistics have been reset');

        return Command::SUCCESS;
    }

    /**
     * Safely convert a mixed value to float.
     *
     * @param mixed $value
     */
    private function toFloat($value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
