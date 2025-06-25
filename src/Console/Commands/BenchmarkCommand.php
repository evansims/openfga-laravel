<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\Testing\PerformanceTesting;

/**
 * Command to run performance benchmarks for OpenFGA operations
 */
class BenchmarkCommand extends Command
{
    protected $signature = 'openfga:benchmark
                            {--iterations=100 : Number of iterations for each benchmark}
                            {--suite=basic : Benchmark suite to run (basic, comprehensive, stress)}
                            {--export= : Export results to file (json or csv)}';

    protected $description = 'Run performance benchmarks for OpenFGA operations';

    private PerformanceTesting $tester;

    public function handle(): int
    {
        $this->tester = new PerformanceTesting()->enableDetailed();
        
        $suite = $this->option('suite');
        $iterations = (int) $this->option('iterations');

        $this->info('🚀 Running OpenFGA Performance Benchmarks');
        $this->info('Suite: ' . $suite);
        $this->info('Iterations: ' . $iterations);
        $this->newLine();

        try {
            $results = match ($suite) {
                'basic' => $this->runBasicSuite($iterations),
                'comprehensive' => $this->runComprehensiveSuite($iterations),
                'stress' => $this->runStressSuite($iterations),
                default => $this->runBasicSuite($iterations),
            };

            $this->displayResults($results);

            if ($exportPath = $this->option('export')) {
                $this->exportResults($results, $exportPath);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Benchmark failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function runBasicSuite(int $iterations): array
    {
        $this->info('Running basic benchmark suite...');
        
        // Single permission check
        $this->line('📊 Benchmarking single permission check...');
        $checkResult = $this->tester->benchmark('Single Check', function () {
            OpenFga::check('user:1', 'viewer', 'document:1');
        }, $iterations);

        // Batch check
        $this->line('📊 Benchmarking batch check (10 items)...');
        $batchCheckResult = $this->tester->benchmark('Batch Check (10)', function () {
            $checks = [];
            for ($i = 1; $i <= 10; $i++) {
                $checks[] = [
                    'user' => 'user:1',
                    'relation' => 'viewer',
                    'object' => "document:{$i}",
                ];
            }
            OpenFga::batchCheck($checks);
        }, $iterations);

        // Single write
        $this->line('📊 Benchmarking single write...');
        $writeResult = $this->tester->benchmark('Single Write', function () {
            OpenFga::grant('user:1', 'editor', 'document:1');
        }, $iterations);

        // Batch write
        $this->line('📊 Benchmarking batch write (10 items)...');
        $batchWriteResult = $this->tester->benchmark('Batch Write (10)', function () {
            $writes = [];
            for ($i = 1; $i <= 10; $i++) {
                $writes[] = [
                    'user' => 'user:1',
                    'relation' => 'editor',
                    'object' => "document:{$i}",
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

    private function runComprehensiveSuite(int $iterations): array
    {
        $this->info('Running comprehensive benchmark suite...');
        
        $results = [];

        // Various batch sizes for checks
        foreach ([1, 5, 10, 25, 50, 100] as $size) {
            $this->line("📊 Benchmarking batch check ({$size} items)...");
            $results["batch_check_{$size}"] = $this->tester->benchmark(
                "Batch Check ({$size})",
                function () use ($size) {
                    $checks = [];
                    for ($i = 1; $i <= $size; $i++) {
                        $checks[] = [
                            'user' => "user:{$i}",
                            'relation' => 'viewer',
                            'object' => "document:{$i}",
                        ];
                    }
                    OpenFga::batchCheck($checks);
                },
                max(10, intval($iterations / ($size / 10)))
            );
        }

        // List operations
        $this->line('📊 Benchmarking list objects...');
        $results['list_objects'] = $this->tester->benchmark('List Objects', function () {
            OpenFga::listObjects('user:1', 'viewer', 'document');
        }, $iterations);

        // Expand operations
        $this->line('📊 Benchmarking expand...');
        $results['expand'] = $this->tester->benchmark('Expand', function () {
            OpenFga::expand('document:1', 'viewer');
        }, $iterations);

        // Complex permission check with contextual tuples
        $this->line('📊 Benchmarking contextual check...');
        $results['contextual_check'] = $this->tester->benchmark('Contextual Check', function () {
            OpenFga::check('user:1', 'editor', 'document:1', [
                'contextualTuples' => [
                    ['user' => 'user:1', 'relation' => 'member', 'object' => 'team:engineering'],
                ],
            ]);
        }, $iterations);

        return [
            'suite' => 'comprehensive',
            'results' => $results,
        ];
    }

    private function runStressSuite(int $iterations): array
    {
        $this->info('Running stress test benchmark suite...');
        $this->warn('This may take a while...');
        
        $results = [];

        // Large batch operations
        foreach ([100, 500, 1000] as $size) {
            $this->line("📊 Stress testing batch write ({$size} items)...");
            $results["stress_write_{$size}"] = $this->tester->benchmark(
                "Stress Write ({$size})",
                function () use ($size) {
                    $writes = [];
                    for ($i = 1; $i <= $size; $i++) {
                        $writes[] = [
                            'user' => "user:{$i}",
                            'relation' => 'viewer',
                            'object' => "document:{$i}",
                        ];
                    }
                    OpenFga::writeBatch($writes);
                },
                max(5, intval($iterations / 20))
            );
        }

        // Concurrent operations simulation
        $this->line('📊 Stress testing concurrent checks...');
        $results['concurrent_checks'] = $this->tester->benchmark(
            'Concurrent Checks (100)',
            function () {
                // Simulate 100 concurrent permission checks
                $promises = [];
                for ($i = 1; $i <= 100; $i++) {
                    // In real implementation, these would be async
                    OpenFga::check("user:{$i}", 'viewer', "document:{$i}");
                }
            },
            max(10, intval($iterations / 10))
        );

        // Memory stress test
        $this->line('📊 Memory stress test...');
        $memoryStart = memory_get_usage();
        
        $results['memory_stress'] = $this->tester->profileMemory(
            'Memory Stress (10k tuples)',
            function () {
                $writes = [];
                for ($i = 1; $i <= 10000; $i++) {
                    $writes[] = [
                        'user' => "user:" . ($i % 100),
                        'relation' => 'viewer',
                        'object' => "document:{$i}",
                    ];
                    
                    // Write in batches of 1000
                    if (count($writes) >= 1000) {
                        OpenFga::writeBatch($writes);
                        $writes = [];
                    }
                }
                if (!empty($writes)) {
                    OpenFga::writeBatch($writes);
                }
            }
        );

        return [
            'suite' => 'stress',
            'results' => $results,
        ];
    }

    private function displayResults(array $results): void
    {
        $this->newLine();
        $this->info('📊 Benchmark Results');
        $this->info('==================');
        
        $tableData = [];
        
        foreach ($results['results'] as $name => $result) {
            if (isset($result['mean'])) {
                $tableData[] = [
                    'Operation' => $result['name'] ?? $name,
                    'Mean (ms)' => round($result['mean'], 3),
                    'Median (ms)' => round($result['median'], 3),
                    'Min (ms)' => round($result['min'], 3),
                    'Max (ms)' => round($result['max'], 3),
                    'P95 (ms)' => round($result['p95'], 3),
                    'StdDev' => round($result['stddev'], 3),
                ];
            }
        }

        $this->table(
            ['Operation', 'Mean (ms)', 'Median (ms)', 'Min (ms)', 'Max (ms)', 'P95 (ms)', 'StdDev'],
            $tableData
        );

        // Display comparison insights
        $this->newLine();
        $this->info('💡 Insights:');
        
        if (isset($results['results']['single_check']) && isset($results['results']['batch_check_10'])) {
            $singleTime = $results['results']['single_check']['mean'];
            $batchTime = $results['results']['batch_check_10']['mean'];
            $efficiency = (($singleTime * 10) / $batchTime - 1) * 100;
            
            if ($efficiency > 0) {
                $this->line(sprintf(
                    '- Batch checking 10 items is %.1f%% more efficient than individual checks',
                    $efficiency
                ));
            }
        }

        // Show performance report
        $this->newLine();
        $this->line($this->tester->generateReport());
    }

    private function exportResults(array $results, string $path): void
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'json';
        
        if ($extension === 'csv') {
            $this->exportCsv($results, $path);
        } else {
            $this->exportJson($results, $path);
        }
        
        $this->info("Results exported to: {$path}");
    }

    private function exportJson(array $results, string $path): void
    {
        $data = [
            'timestamp' => now()->toIso8601String(),
            'environment' => [
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'connection' => config('openfga.default'),
            ],
            'suite' => $results['suite'],
            'results' => $results['results'],
            'summary' => $this->tester->getSummary(),
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function exportCsv(array $results, string $path): void
    {
        $fp = fopen($path, 'w');
        
        // Header
        fputcsv($fp, ['Operation', 'Mean (ms)', 'Median (ms)', 'Min (ms)', 'Max (ms)', 'P95 (ms)', 'StdDev', 'Iterations']);
        
        // Data
        foreach ($results['results'] as $name => $result) {
            if (isset($result['mean'])) {
                fputcsv($fp, [
                    $result['name'] ?? $name,
                    round($result['mean'], 3),
                    round($result['median'], 3),
                    round($result['min'], 3),
                    round($result['max'], 3),
                    round($result['p95'], 3),
                    round($result['stddev'], 3),
                    $result['iterations'] ?? 0,
                ]);
            }
        }
        
        fclose($fp);
    }
}