<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\Testing\PerformanceTesting;

use function count;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Command to run performance benchmarks for OpenFGA operations.
 */
final class BenchmarkCommand extends Command
{
    /**
     * @var string|null
     */
    protected $description = 'Run performance benchmarks for OpenFGA operations';

    protected $signature = 'openfga:benchmark
                            {--iterations=100 : Number of iterations for each benchmark}
                            {--suite=basic : Benchmark suite to run (basic, comprehensive, stress)}
                            {--export= : Export results to file (json or csv)}';

    public function handle(): int
    {
        $tester = new PerformanceTesting;
        $tester->enableDetailed();

        $suite = $this->option('suite');

        if (! is_string($suite)) {
            $suite = 'basic';
        }
        $iterationsOption = $this->option('iterations');
        $iterations = is_numeric($iterationsOption) ? (int) $iterationsOption : 100;

        $this->info('🚀 Running OpenFGA Performance Benchmarks');
        $this->info('Suite: ' . $suite);
        $this->info('Iterations: ' . $iterations);
        $this->newLine();

        try {
            /** @var array{suite: string, results: array<string, mixed>} $results */
            $results = match ($suite) {
                'basic' => $this->runBasicSuite($iterations, $tester),
                'comprehensive' => $this->runComprehensiveSuite($iterations, $tester),
                'stress' => $this->runStressSuite($iterations, $tester),
                default => $this->runBasicSuite($iterations, $tester),
            };

            $this->displayResults($results, $tester);

            $exportPath = $this->option('export');

            if (is_string($exportPath)) {
                $this->exportResults($results, $exportPath, $tester);
            }

            return 0;
        } catch (Exception $exception) {
            $this->error('Benchmark failed: ' . $exception->getMessage());

            return 1;
        }
    }

    /**
     * @param array{suite: string, results: array<string, mixed>} $results
     * @param PerformanceTesting                                  $tester
     */
    private function displayResults(array $results, PerformanceTesting $tester): void
    {
        $this->newLine();
        $this->info('📊 Benchmark Results');
        $this->info('==================');

        $tableData = [];

        foreach ($results['results'] as $name => $result) {
            if (! is_array($result)) {
                continue;
            }

            // Handle benchmark results
            if (isset($result['mean'], $result['median'])) {
                $tableData[] = [
                    'Operation' => isset($result['name']) && is_string($result['name']) ? $result['name'] : $name,
                    'Mean (ms)' => round(is_numeric($result['mean']) ? (float) $result['mean'] : 0.0, 3),
                    'Median (ms)' => round(is_numeric($result['median']) ? (float) $result['median'] : 0.0, 3),
                    'Min (ms)' => round(isset($result['min']) && is_numeric($result['min']) ? (float) $result['min'] : 0.0, 3),
                    'Max (ms)' => round(isset($result['max']) && is_numeric($result['max']) ? (float) $result['max'] : 0.0, 3),
                    'P95 (ms)' => round(isset($result['p95']) && is_numeric($result['p95']) ? (float) $result['p95'] : 0.0, 3),
                    'StdDev' => round(isset($result['stddev']) && is_numeric($result['stddev']) ? (float) $result['stddev'] : 0.0, 3),
                ];
            }
            // Handle memory profiling results
            elseif (isset($result['duration'], $result['memory_usage'])) {
                $tableData[] = [
                    'Operation' => isset($result['name']) && is_string($result['name']) ? $result['name'] : $name,
                    'Mean (ms)' => round(is_numeric($result['duration']) ? (float) $result['duration'] : 0.0, 3),
                    'Median (ms)' => '-',
                    'Min (ms)' => '-',
                    'Max (ms)' => '-',
                    'P95 (ms)' => '-',
                    'StdDev' => '-',
                ];
            }
        }

        $this->table(
            ['Operation', 'Mean (ms)', 'Median (ms)', 'Min (ms)', 'Max (ms)', 'P95 (ms)', 'StdDev'],
            $tableData,
        );

        // Display comparison insights
        $this->newLine();
        $this->info('💡 Insights:');

        if (isset($results['results']['single_check'], $results['results']['batch_check_10'])
            && is_array($results['results']['single_check']) && is_array($results['results']['batch_check_10'])
            && isset($results['results']['single_check']['mean'], $results['results']['batch_check_10']['mean'])
            && is_numeric($results['results']['single_check']['mean']) && is_numeric($results['results']['batch_check_10']['mean'])) {
            $singleTime = (float) $results['results']['single_check']['mean'];
            $batchTime = (float) $results['results']['batch_check_10']['mean'];
            $efficiency = (($singleTime * 10.0) / $batchTime - 1.0) * 100.0;

            if (0 < $efficiency) {
                $this->line(sprintf(
                    '- Batch checking 10 items is %.1f%% more efficient than individual checks',
                    $efficiency,
                ));
            }
        }

        // Show performance report
        $this->newLine();
        $this->line($tester->generateReport());
    }

    /**
     * @param array{suite: string, results: array<string, mixed>} $results
     * @param string                                              $path
     */
    private function exportCsv(array $results, string $path): void
    {
        $fp = fopen($path, 'w');

        if (false === $fp) {
            return;
        }

        // Header
        fputcsv($fp, ['Operation', 'Mean (ms)', 'Median (ms)', 'Min (ms)', 'Max (ms)', 'P95 (ms)', 'StdDev', 'Iterations']);

        // Data
        foreach ($results['results'] as $name => $result) {
            if (! is_array($result)) {
                continue;
            }

            // Handle benchmark results
            if (isset($result['mean'], $result['iterations'])) {
                fputcsv($fp, [
                    isset($result['name']) && is_string($result['name']) ? $result['name'] : $name,
                    (string) round(isset($result['mean']) && is_numeric($result['mean']) ? (float) $result['mean'] : 0.0, 3),
                    (string) round(isset($result['median']) && is_numeric($result['median']) ? (float) $result['median'] : 0.0, 3),
                    (string) round(isset($result['min']) && is_numeric($result['min']) ? (float) $result['min'] : 0.0, 3),
                    (string) round(isset($result['max']) && is_numeric($result['max']) ? (float) $result['max'] : 0.0, 3),
                    (string) round(isset($result['p95']) && is_numeric($result['p95']) ? (float) $result['p95'] : 0.0, 3),
                    (string) round(isset($result['stddev']) && is_numeric($result['stddev']) ? (float) $result['stddev'] : 0.0, 3),
                    (string) (isset($result['iterations']) && is_int($result['iterations']) ? $result['iterations'] : 0),
                ]);
            }
            // Handle memory profiling results
            elseif (isset($result['duration'])) {
                fputcsv($fp, [
                    isset($result['name']) && is_string($result['name']) ? $result['name'] : $name,
                    (string) round(isset($result['duration']) && is_numeric($result['duration']) ? (float) $result['duration'] : 0.0, 3),
                    '-',
                    '-',
                    '-',
                    '-',
                    '-',
                    '1',
                ]);
            }
        }

        fclose($fp);
    }

    /**
     * @param array<string, mixed> $results
     * @param string               $path
     * @param PerformanceTesting   $tester
     */
    private function exportJson(array $results, string $path, PerformanceTesting $tester): void
    {
        $data = [
            'timestamp' => now()->toIso8601String(),
            'environment' => [
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'connection' => config('openfga.default'),
            ],
            'suite' => $results['suite'] ?? 'unknown',
            'results' => $results['results'] ?? [],
            'summary' => $tester->getSummary(),
        ];

        $encoded = json_encode($data, JSON_PRETTY_PRINT);

        if (false !== $encoded) {
            file_put_contents($path, $encoded);
        }
    }

    /**
     * @param array{suite: string, results: array<string, mixed>} $results
     * @param string                                              $path
     * @param PerformanceTesting                                  $tester
     */
    private function exportResults(array $results, string $path, PerformanceTesting $tester): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ('' === $extension) {
            $extension = 'json';
        }

        if ('csv' === $extension) {
            $this->exportCsv($results, $path);
        } else {
            $this->exportJson($results, $path, $tester);
        }

        $this->info('Results exported to: ' . $path);
    }

    /**
     * @param  int                                                                                                                                                                           $iterations
     * @param  PerformanceTesting                                                                                                                                                            $tester
     * @return array{suite: string, results: array<string, array{name: string, iterations: int, mean: float, median: float, min: float, max: float, p95: float, p99: float, stddev: float}>}
     */
    private function runBasicSuite(int $iterations, PerformanceTesting $tester): array
    {
        $this->info('Running basic benchmark suite...');

        // Single permission check
        $this->line('📊 Benchmarking single permission check...');
        $checkResult = $tester->benchmark('Single Check', static function (): void {
            OpenFga::check('user:1', 'viewer', 'document:1');
        }, $iterations);

        // Batch check
        $this->line('📊 Benchmarking batch check (10 items)...');
        $batchCheckResult = $tester->benchmark('Batch Check (10)', static function (): void {
            $checks = [];

            for ($i = 1; 10 >= $i; ++$i) {
                $checks[] = [
                    'user' => 'user:1',
                    'relation' => 'viewer',
                    'object' => 'document:' . $i,
                ];
            }
            OpenFga::batchCheck($checks);
        }, $iterations);

        // Single write
        $this->line('📊 Benchmarking single write...');
        $writeResult = $tester->benchmark('Single Write', static function (): void {
            OpenFga::writeBatch([
                ['user' => 'user:1', 'relation' => 'editor', 'object' => 'document:1'],
            ]);
        }, $iterations);

        // Batch write
        $this->line('📊 Benchmarking batch write (10 items)...');
        $batchWriteResult = $tester->benchmark('Batch Write (10)', static function (): void {
            $writes = [];

            for ($i = 1; 10 >= $i; ++$i) {
                $writes[] = [
                    'user' => 'user:1',
                    'relation' => 'editor',
                    'object' => 'document:' . $i,
                ];
            }
            OpenFga::writeBatch($writes);
        }, $iterations);

        return [
            'suite' => 'basic',
            'results' => [
                'single_check' => $checkResult,
                'batch_check_10' => $batchCheckResult,
                'single_write' => $writeResult,
                'batch_write_10' => $batchWriteResult,
            ],
        ];
    }

    /**
     * @param  int                                                                                                                                                                           $iterations
     * @param  PerformanceTesting                                                                                                                                                            $tester
     * @return array{suite: string, results: array<string, array{name: string, iterations: int, mean: float, median: float, min: float, max: float, p95: float, p99: float, stddev: float}>}
     */
    private function runComprehensiveSuite(int $iterations, PerformanceTesting $tester): array
    {
        $this->info('Running comprehensive benchmark suite...');

        $results = [];

        // Various batch sizes for checks
        foreach ([1, 5, 10, 25, 50, 100] as $size) {
            $this->line(sprintf('📊 Benchmarking batch check (%s items)...', $size));
            $results['batch_check_' . $size] = $tester->benchmark(
                sprintf('Batch Check (%s)', $size),
                static function () use ($size): void {
                    $checks = [];

                    for ($i = 1; $i <= $size; ++$i) {
                        $checks[] = [
                            'user' => 'user:' . $i,
                            'relation' => 'viewer',
                            'object' => 'document:' . $i,
                        ];
                    }
                    OpenFga::batchCheck($checks);
                },
                max(10, (int) ((float) $iterations / ((float) $size / 10.0))),
            );
        }

        // List operations
        $this->line('📊 Benchmarking list objects...');
        $results['list_objects'] = $tester->benchmark('List Objects', static function (): void {
            OpenFga::listObjects('user:1', 'viewer', 'document');
        }, $iterations);

        // Expand operations
        $this->line('📊 Benchmarking expand...');
        $results['expand'] = $tester->benchmark('Expand', static function (): void {
            OpenFga::expand('document:1', 'viewer');
        }, $iterations);

        // Complex permission check with contextual tuples
        $this->line('📊 Benchmarking contextual check...');
        $results['contextual_check'] = $tester->benchmark('Contextual Check', static function (): void {
            OpenFga::check('user:1', 'editor', 'document:1');
        }, $iterations);

        return [
            'suite' => 'comprehensive',
            'results' => $results,
        ];
    }

    /**
     * @param  int                                                 $iterations
     * @param  PerformanceTesting                                  $tester
     * @return array{suite: string, results: array<string, mixed>}
     */
    private function runStressSuite(int $iterations, PerformanceTesting $tester): array
    {
        $this->info('Running stress test benchmark suite...');
        $this->warn('This may take a while...');

        $results = [];

        // Large batch operations
        foreach ([100, 500, 1000] as $size) {
            $this->line(sprintf('📊 Stress testing batch write (%s items)...', $size));
            $results['stress_write_' . $size] = $tester->benchmark(
                sprintf('Stress Write (%s)', $size),
                static function () use ($size): void {
                    $writes = [];

                    for ($i = 1; $i <= $size; ++$i) {
                        $writes[] = [
                            'user' => 'user:' . $i,
                            'relation' => 'viewer',
                            'object' => 'document:' . $i,
                        ];
                    }
                    OpenFga::writeBatch($writes);
                },
                max(5, (int) ($iterations / 20)),
            );
        }

        // Concurrent operations simulation
        $this->line('📊 Stress testing concurrent checks...');
        $results['concurrent_checks'] = $tester->benchmark(
            'Concurrent Checks (100)',
            static function (): void {
                // Simulate 100 concurrent permission checks

                for ($i = 1; 100 >= $i; ++$i) {
                    // In real implementation, these would be async
                    OpenFga::check('user:' . $i, 'viewer', 'document:' . $i);
                }
            },
            max(10, (int) ($iterations / 10)),
        );

        // Memory stress test
        $this->line('📊 Memory stress test...');
        memory_get_usage();

        $results['memory_stress'] = $tester->profileMemory(
            'Memory Stress (10k tuples)',
            static function (): void {
                $writes = [];

                for ($i = 1; 10000 >= $i; ++$i) {
                    $writes[] = [
                        'user' => 'user:' . ($i % 100),
                        'relation' => 'viewer',
                        'object' => 'document:' . $i,
                    ];

                    // Write in batches of 1000
                    if (1000 <= count($writes)) {
                        OpenFga::writeBatch($writes);
                        $writes = [];
                    }
                }

                if ([] !== $writes) {
                    OpenFga::writeBatch($writes);
                }
            },
        );

        return [
            'suite' => 'stress',
            'results' => $results,
        ];
    }
}
