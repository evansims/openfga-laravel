<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Models\Collections\TupleKeys;
use OpenFGA\Models\TupleKey;

use function count;
use function is_string;
use function sprintf;

/**
 * Command to revoke a permission from a user.
 */
final class RevokeCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Revoke a permission from a user on an object';

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
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     */
    public function handle(OpenFgaManager $manager): int
    {
        $connectionOption = $this->option('connection');
        $connection = is_string($connectionOption) ? $connectionOption : null;

        try {
            $batchOption = $this->option('batch');

            if (true === $batchOption) {
                return $this->handleBatch($manager, $connection);
            }

            return $this->handleSingle($manager, $connection);
        } catch (Exception $exception) {
            $jsonOption = $this->option('json');

            if (true === $jsonOption) {
                $jsonOutput = json_encode([
                    'error' => true,
                    'message' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT);

                if (false !== $jsonOutput) {
                    $this->output->writeln($jsonOutput);
                }
            } else {
                $this->error('Error: ' . $exception->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Handle batch revoke operations.
     *
     * @param OpenFgaManager $manager
     * @param string|null    $connection
     */
    private function handleBatch(OpenFgaManager $manager, ?string $connection): int
    {
        $this->info('Reading tuples from stdin (format: user:relation:object per line, empty line to finish)...');

        $tuples = [];

        while (($input = fgets(STDIN)) !== false) {
            $line = trim($input);

            if ('' === $line) {
                break;
            }
            $parts = explode(':', $line, 3);

            if (3 !== count($parts)) {
                $this->warn(sprintf('Skipping invalid tuple format: %s. Expected format: user:relation:object', $line));

                continue;
            }

            $tuples[] = [
                'user' => $parts[0],
                'relation' => $parts[1],
                'object' => $parts[2],
            ];
        }

        if ([] === $tuples) {
            $this->warn('No valid tuples provided');

            return Command::FAILURE;
        }

        $this->info('Processing ' . count($tuples) . ' tuples...');

        $startTime = microtime(true);

        // Convert tuples to TupleKey objects for deletion
        $deleteKeys = [];

        foreach ($tuples as $tuple) {
            $deleteKeys[] = new TupleKey(
                user: $tuple['user'],
                relation: $tuple['relation'],
                object: $tuple['object'],
            );
        }
        $deletes = new TupleKeys($deleteKeys);

        $manager->write(null, $deletes, $connection);

        $duration = round((float) ((microtime(true) - $startTime) * 1000), 2);

        $jsonOption = $this->option('json');

        if (true === $jsonOption) {
            $jsonOutput = json_encode([
                'success' => true,
                'operation' => 'revoke_batch',
                'tuples_count' => count($tuples),
                'tuples' => $tuples,
                'duration_ms' => $duration,
            ], JSON_PRETTY_PRINT);

            if (false !== $jsonOutput) {
                $this->output->writeln($jsonOutput);
            }
        } else {
            $this->info('✅ Batch revoke completed successfully');

            $this->table(
                ['Field', 'Value'],
                [
                    ['Tuples Revoked', count($tuples)],
                    ['Duration', ((string) $duration) . 'ms'],
                ],
            );

            if ($this->output->isVerbose()) {
                $this->info("\nRevoked tuples:");

                foreach ($tuples as $tuple) {
                    $this->line(sprintf('  - %s#%s@%s', $tuple['user'], $tuple['relation'], $tuple['object']));
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Handle a single revoke operation.
     *
     * @param OpenFgaManager $manager
     * @param string|null    $connection
     */
    private function handleSingle(OpenFgaManager $manager, ?string $connection): int
    {
        $userArg = $this->argument('user');
        $relationArg = $this->argument('relation');
        $objectArg = $this->argument('object');

        if (! is_string($userArg) || ! is_string($relationArg) || ! is_string($objectArg)) {
            $this->error('Invalid arguments provided');

            return Command::FAILURE;
        }

        $user = $userArg;
        $relation = $relationArg;
        $object = $objectArg;

        $startTime = microtime(true);

        $manager->revoke($user, $relation, $object, $connection);

        $duration = round((float) ((microtime(true) - $startTime) * 1000), 2);

        $jsonOption = $this->option('json');

        if (true === $jsonOption) {
            $jsonOutput = json_encode([
                'success' => true,
                'operation' => 'revoke',
                'user' => $user,
                'relation' => $relation,
                'object' => $object,
                'duration_ms' => $duration,
            ], JSON_PRETTY_PRINT);

            if (false !== $jsonOutput) {
                $this->output->writeln($jsonOutput);
            }
        } else {
            $this->info('✅ Permission revoked successfully');

            $this->table(
                ['Field', 'Value'],
                [
                    ['User', $user],
                    ['Relation', $relation],
                    ['Object', $object],
                    ['Duration', ((string) $duration) . 'ms'],
                ],
            );
        }

        return Command::SUCCESS;
    }
}
