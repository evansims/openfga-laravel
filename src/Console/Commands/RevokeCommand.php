<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Command to revoke a permission from a user.
 */
class RevokeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:revoke {user} {relation} {object}
                            {--connection= : The connection to use}
                            {--json : Output as JSON}
                            {--batch : Read additional tuples from stdin for batch operation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Revoke a permission from a user on an object';

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
     * Handle a single revoke operation.
     */
    protected function handleSingle(OpenFgaManager $manager): int
    {
        $user = $this->argument('user');
        $relation = $this->argument('relation');
        $object = $this->argument('object');

        $startTime = microtime(true);
        
        $manager->revoke($user, $relation, $object);
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'success' => true,
                'operation' => 'revoke',
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
                'duration_ms' => $duration,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info("✅ Permission revoked successfully");
            
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
     * Handle batch revoke operations.
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
        
        $manager->writeBatch([], $tuples); // Pass tuples as deletes
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'success' => true,
                'operation' => 'revoke_batch',
                'tuples_count' => count($tuples),
                'tuples' => $tuples,
                'duration_ms' => $duration,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info("✅ Batch revoke completed successfully");
            
            $this->table(
                ['Field', 'Value'],
                [
                    ['Tuples Revoked', count($tuples)],
                    ['Duration', "{$duration}ms"],
                ]
            );

            if ($this->output->isVerbose()) {
                $this->info("\nRevoked tuples:");
                foreach ($tuples as $tuple) {
                    $this->line("  - {$tuple['user']}#{$tuple['relation']}@{$tuple['object']}");
                }
            }
        }

        return Command::SUCCESS;
    }
}