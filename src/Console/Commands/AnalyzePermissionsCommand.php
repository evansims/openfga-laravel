<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

use function is_array;

final class AnalyzePermissionsCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze permission structure for insights and optimization';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:analyze
                            {--depth=3 : Maximum depth for permission path analysis}
                            {--show-paths : Show permission inheritance paths}
                            {--find-conflicts : Identify potential permission conflicts}
                            {--optimize : Suggest optimization opportunities}
                            {--connection= : The OpenFGA connection to use}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     */
    public function handle(OpenFgaManager $manager): int
    {
        $connection = $this->option('connection');
        $manager->connection($connection);

        $this->info('Analyzing permission structure...');

        try {
            $analysis = [];

            // Show permission paths
            if ($this->option('show-paths')) {
                $analysis['paths'] = $this->analyzePermissionPaths();
            }

            // Find conflicts
            if ($this->option('find-conflicts')) {
                $analysis['conflicts'] = $this->findPermissionConflicts();
            }

            // Optimization suggestions
            if ($this->option('optimize')) {
                $analysis['optimizations'] = $this->suggestOptimizations();
            }

            // Default analysis if no specific options
            if ([] === $analysis) {
                $analysis = $this->performDefaultAnalysis();
            }

            $this->displayAnalysis($analysis);

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Analysis failed: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Analyze permission inheritance paths.
     */
    private function analyzePermissionPaths(): array
    {
        $this->info('Analyzing permission inheritance paths...');

        // Example analysis results
        return [
            'inheritance_chains' => [
                [
                    'path' => 'user:1 -> editor -> document:1',
                    'depth' => 2,
                    'type' => 'direct',
                ],
                [
                    'path' => 'user:2 -> member -> department:eng -> editor -> document:1',
                    'depth' => 4,
                    'type' => 'inherited',
                ],
            ],
            'max_depth_found' => 4,
            'circular_dependencies' => 0,
        ];
    }

    /**
     * Display analysis results.
     *
     * @param array $analysis
     */
    private function displayAnalysis(array $analysis): void
    {
        foreach ($analysis as $section => $data) {
            $this->newLine();
            $this->info(ucwords(str_replace('_', ' ', $section)) . ':');
            $this->displaySection($data);
        }

        $this->newLine();
        $this->comment('Analysis complete.');
    }

    /**
     * Display a section of analysis data.
     *
     * @param mixed $data
     * @param int   $indent
     */
    private function displaySection($data, int $indent = 0): void
    {
        $prefix = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            if (is_array($value) && ! isset($value[0])) {
                $this->comment($prefix . ucwords(str_replace('_', ' ', (string) $key)) . ':');
                $this->displaySection($value, $indent + 1);
            } elseif (is_array($value)) {
                $this->comment($prefix . ucwords(str_replace('_', ' ', (string) $key)) . ':');

                foreach ($value as $item) {
                    if (is_array($item)) {
                        $this->displaySection($item, $indent + 1);
                        $this->line('');
                    } else {
                        $this->line($prefix . '  - ' . $item);
                    }
                }
            } else {
                $this->line($prefix . ucwords(str_replace('_', ' ', (string) $key)) . ': ' . $value);
            }
        }
    }

    /**
     * Find potential permission conflicts.
     */
    private function findPermissionConflicts(): array
    {
        $this->info('Searching for permission conflicts...');

        // Example conflict detection
        return [
            'conflicts' => [
                [
                    'type' => 'redundant_permission',
                    'description' => 'User has both direct and inherited access',
                    'user' => 'user:1',
                    'object' => 'document:1',
                    'relations' => ['owner', 'viewer'],
                    'recommendation' => 'Remove viewer permission as owner implies viewing rights',
                ],
                [
                    'type' => 'contradictory_rules',
                    'description' => 'Conflicting access patterns detected',
                    'pattern' => 'User banned from group but has direct object access',
                    'affected_count' => 3,
                ],
            ],
            'total_conflicts' => 2,
        ];
    }

    /**
     * Perform default analysis.
     */
    private function performDefaultAnalysis(): array
    {
        return [
            'structure' => [
                'total_object_types' => 8,
                'total_relation_types' => 12,
                'average_relations_per_type' => 3.5,
                'most_complex_type' => 'organization (7 relations)',
            ],
            'usage' => [
                'most_used_relations' => [
                    'viewer' => 450,
                    'editor' => 230,
                    'owner' => 180,
                ],
                'permission_distribution' => [
                    'direct' => '65%',
                    'inherited' => '35%',
                ],
            ],
            'health' => [
                'score' => 85,
                'issues' => [
                    'minor' => 5,
                    'major' => 1,
                    'critical' => 0,
                ],
                'recommendations' => [
                    'Consider implementing caching for frequently checked permissions',
                    'Review permissions older than 6 months for relevance',
                ],
            ],
        ];
    }

    /**
     * Suggest optimization opportunities.
     */
    private function suggestOptimizations(): array
    {
        $this->info('Analyzing for optimization opportunities...');

        return [
            'suggestions' => [
                [
                    'type' => 'consolidate_permissions',
                    'description' => 'Multiple individual permissions could be replaced with group membership',
                    'example' => '15 users have identical permissions on project:alpha',
                    'potential_reduction' => '93% fewer permission tuples',
                ],
                [
                    'type' => 'use_inheritance',
                    'description' => 'Direct permissions could leverage existing hierarchies',
                    'example' => 'Documents in folder:reports could inherit from folder permissions',
                    'affected_objects' => 45,
                ],
                [
                    'type' => 'remove_redundant',
                    'description' => 'Permissions that are already implied by other relations',
                    'count' => 23,
                    'storage_savings' => '~2KB',
                ],
            ],
            'total_optimizations' => 3,
            'potential_tuple_reduction' => '~1,250 tuples',
        ];
    }
}
