<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Monitoring\PerformanceMonitor;

/**
 * Command to display OpenFGA performance statistics.
 */
class StatsCommand extends Command
{
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
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display OpenFGA performance statistics';

    /**
     * Execute the console command.
     */
    public function handle(PerformanceMonitor $monitor): int
    {
        if ($this->option('reset')) {
            return $this->handleReset($monitor);
        }

        $metric = $this->option('metric');
        $minutes = (int) $this->option('minutes');
        
        $stats = $monitor->getStatistics($metric, $minutes);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($stats, JSON_PRETTY_PRINT));
        } else {
            $this->displayStatistics($stats, $minutes);
        }

        return Command::SUCCESS;
    }

    /**
     * Handle resetting statistics.
     */
    protected function handleReset(PerformanceMonitor $monitor): int
    {
        if (!$this->confirm('Are you sure you want to reset all statistics?')) {
            return Command::SUCCESS;
        }

        $monitor->reset();
        
        $this->info('✅ All statistics have been reset');
        
        return Command::SUCCESS;
    }

    /**
     * Display statistics in a formatted way.
     */
    protected function displayStatistics(array $stats, int $minutes): void
    {
        $this->info("OpenFGA Performance Statistics (last {$minutes} minutes)");
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
     * Display permission check statistics.
     */
    protected function displayPermissionCheckStats(array $stats): void
    {
        $this->info('📊 Permission Checks:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Checks', number_format($stats['count'])],
                ['Average Duration', $this->formatDuration($stats['avg_duration'])],
                ['Min Duration', $this->formatDuration($stats['min_duration'])],
                ['Max Duration', $this->formatDuration($stats['max_duration'])],
            ]
        );
        $this->newLine();
    }

    /**
     * Display batch write statistics.
     */
    protected function displayBatchWriteStats(array $stats): void
    {
        $this->info('📝 Batch Writes:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Batches', number_format($stats['count'])],
                ['Average Duration', $this->formatDuration($stats['avg_duration'])],
                ['Min Duration', $this->formatDuration($stats['min_duration'])],
                ['Max Duration', $this->formatDuration($stats['max_duration'])],
            ]
        );
        $this->newLine();
    }

    /**
     * Display cache statistics.
     */
    protected function displayCacheStats(array $stats): void
    {
        $this->info('💾 Cache Performance:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Cache Hits', number_format($stats['hits'])],
                ['Cache Misses', number_format($stats['misses'])],
                ['Hit Rate', number_format($stats['hit_rate'], 2) . '%'],
            ]
        );
        $this->newLine();
    }

    /**
     * Display overall performance statistics.
     */
    protected function displayPerformanceStats(array $stats): void
    {
        $this->info('⚡ Overall Performance:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Operations', number_format($stats['total_operations'])],
                ['Average Response Time', $this->formatDuration($stats['avg_response_time'])],
                ['Operations per Minute', number_format($stats['operations_per_minute'], 2)],
            ]
        );
        $this->newLine();
    }

    /**
     * Format a duration value.
     */
    protected function formatDuration(float $seconds): string
    {
        $milliseconds = $seconds * 1000;
        
        if ($milliseconds < 1) {
            return number_format($milliseconds * 1000, 2) . 'μs';
        }
        
        if ($milliseconds < 1000) {
            return number_format($milliseconds, 2) . 'ms';
        }
        
        return number_format($seconds, 2) . 's';
    }
}