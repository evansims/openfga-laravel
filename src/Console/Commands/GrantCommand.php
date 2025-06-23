<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Command to grant a permission to a user.
 */
class GrantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:grant {user} {relation} {object}
                            {--connection= : The connection to use}
                            {--json : Output as JSON}
                            {--batch : Read additional tuples from stdin for batch operation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant a permission to a user on an object';

    /**
     * Execute the console command.
     */
    public function handle(OpenFgaManager $manager): int
    {
        $connection = $this->option('connection');
        $manager = $manager->connection($connection);

        try {
            if ($this->option('batch')) {
                return $this->handleBatch($manager);
            }

            return $this->handleSingle($manager);
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
     * Handle a single grant operation.
     */
    protected function handleSingle(OpenFgaManager $manager): int
    {
        $user = $this->argument('user');
        $relation = $this->argument('relation');
        $object = $this->argument('object');

        $startTime = microtime(true);
        
        $manager->grant($user, $relation, $object);
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'success' => true,
                'operation' => 'grant',
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
                'duration_ms' => $duration,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info("✅ Permission granted successfully");
            
            $this->table(
                ['Field', 'Value'],
                [
                    ['User', $user],
                    ['Relation', $relation],
                    ['Object', $object],
                    ['Duration', "{$duration}ms"],
                ]
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Handle batch grant operations.
     */
    protected function handleBatch(OpenFgaManager $manager): int
    {
        $this->info("Reading tuples from stdin (format: user:relation:object per line, empty line to finish)...");

        $tuples = [];
        while ($line = trim(fgets(STDIN))) {
            $parts = explode(':', $line, 3);
            
            if (count($parts) !== 3) {
                $this->warn("Skipping invalid tuple format: {$line}. Expected format: user:relation:object");
                continue;
            }

            $tuples[] = [
                'user' => $parts[0],
                'relation' => $parts[1],
                'object' => $parts[2],
            ];
        }

        if (empty($tuples)) {
            $this->warn("No valid tuples provided");
            return Command::FAILURE;
        }

        $this->info("Processing " . count($tuples) . " tuples...");

        $startTime = microtime(true);
        
        $manager->writeBatch($tuples);
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'success' => true,
                'operation' => 'grant_batch',
                'tuples_count' => count($tuples),
                'tuples' => $tuples,
                'duration_ms' => $duration,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info("✅ Batch grant completed successfully");
            
            $this->table(
                ['Field', 'Value'],
                [
                    ['Tuples Granted', count($tuples)],
                    ['Duration', "{$duration}ms"],
                ]
            );

            if ($this->output->isVerbose()) {
                $this->info("\nGranted tuples:");
                foreach ($tuples as $tuple) {
                    $this->line("  - {$tuple['user']}#{$tuple['relation']}@{$tuple['object']}");
                }
            }
        }

        return Command::SUCCESS;
    }
}