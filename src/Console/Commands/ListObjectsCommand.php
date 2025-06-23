<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Command to list objects that a user has a specific relation to.
 */
class ListObjectsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:list-objects {user} {relation} {type}
                            {--connection= : The connection to use}
                            {--json : Output as JSON}
                            {--contextual-tuple=* : Contextual tuples in format user:relation:object}
                            {--context=* : Context values in format key=value}
                            {--limit=100 : Maximum number of objects to return}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List objects that a user has a specific relation to';

    /**
     * Execute the console command.
     */
    public function handle(OpenFgaManager $manager): int
    {
        $user = $this->argument('user');
        $relation = $this->argument('relation');
        $type = $this->argument('type');
        $connection = $this->option('connection');
        $limit = (int) $this->option('limit');

        // Parse contextual tuples
        $contextualTuples = $this->parseContextualTuples($this->option('contextual-tuple'));
        
        // Parse context
        $context = $this->parseContext($this->option('context'));

        try {
            $startTime = microtime(true);
            
            $objects = $manager
                ->connection($connection)
                ->listObjects($user, $relation, $type, $contextualTuples, $context);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Apply limit if specified
            if ($limit > 0 && count($objects) > $limit) {
                $truncated = true;
                $objects = array_slice($objects, 0, $limit);
            } else {
                $truncated = false;
            }

            if ($this->option('json')) {
                $this->output->writeln(json_encode([
                    'success' => true,
                    'user' => $user,
                    'relation' => $relation,
                    'type' => $type,
                    'objects' => $objects,
                    'count' => count($objects),
                    'truncated' => $truncated,
                    'duration_ms' => $duration,
                    'connection' => $connection ?? 'default',
                ], JSON_PRETTY_PRINT));
            } else {
                $this->displayResults($objects, $user, $relation, $type, $duration, $truncated);

                if (!empty($contextualTuples)) {
                    $this->info("\nContextual Tuples:");
                    foreach ($contextualTuples as $tuple) {
                        $this->line("  - {$tuple['user']}#{$tuple['relation']}@{$tuple['object']}");
                    }
                }

                if (!empty($context)) {
                    $this->info("\nContext:");
                    foreach ($context as $key => $value) {
                        $this->line("  - {$key}: {$value}");
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->output->writeln(json_encode([
                    'error' => true,
                    'message' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error("Error: " . $e->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Display the results in a formatted table.
     *
     * @param array<string> $objects
     */
    protected function displayResults(
        array $objects,
        string $user,
        string $relation,
        string $type,
        float $duration,
        bool $truncated
    ): void {
        if (empty($objects)) {
            $this->warn("No objects found where {$user} has {$relation} permission on type {$type}");
            return;
        }

        $this->info("Objects where {$user} has {$relation} permission:");
        $this->newLine();

        // Group objects by type prefix if they have different types
        $grouped = $this->groupObjectsByType($objects);

        if (count($grouped) === 1) {
            // All objects are of the same type
            foreach ($objects as $object) {
                $this->line("  - {$object}");
            }
        } else {
            // Objects have different types, group them
            foreach ($grouped as $objectType => $objectList) {
                $this->info("  {$objectType}:");
                foreach ($objectList as $object) {
                    $this->line("    - {$object}");
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Objects', count($objects) . ($truncated ? ' (truncated)' : '')],
                ['Duration', "{$duration}ms"],
                ['Connection', $this->option('connection') ?? 'default'],
            ]
        );
    }

    /**
     * Group objects by their type prefix.
     *
     * @param array<string> $objects
     * @return array<string, array<string>>
     */
    protected function groupObjectsByType(array $objects): array
    {
        $grouped = [];

        foreach ($objects as $object) {
            $parts = explode(':', $object, 2);
            $type = count($parts) === 2 ? $parts[0] : 'unknown';
            
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            
            $grouped[$type][] = $object;
        }

        return $grouped;
    }

    /**
     * Parse contextual tuples from command options.
     *
     * @param array<string> $tuples
     * @return array<array{user: string, relation: string, object: string}>
     */
    protected function parseContextualTuples(array $tuples): array
    {
        $parsed = [];

        foreach ($tuples as $tuple) {
            $parts = explode(':', $tuple, 3);
            
            if (count($parts) !== 3) {
                $this->warn("Invalid contextual tuple format: {$tuple}. Expected format: user:relation:object");
                continue;
            }

            $parsed[] = [
                'user' => $parts[0],
                'relation' => $parts[1],
                'object' => $parts[2],
            ];
        }

        return $parsed;
    }

    /**
     * Parse context values from command options.
     *
     * @param array<string> $contextValues
     * @return array<string, mixed>
     */
    protected function parseContext(array $contextValues): array
    {
        $context = [];

        foreach ($contextValues as $value) {
            $parts = explode('=', $value, 2);
            
            if (count($parts) !== 2) {
                $this->warn("Invalid context format: {$value}. Expected format: key=value");
                continue;
            }

            $context[$parts[0]] = $parts[1];
        }

        return $context;
    }
}