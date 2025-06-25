<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\OpenFgaManager;

use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Command to check if a user has a specific permission.
 */
final class CheckCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Check if a user has a specific permission on an object';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:check {user} {relation} {object}
                            {--connection= : The connection to use}
                            {--json : Output as JSON}
                            {--contextual-tuple=* : Contextual tuples in format user:relation:object}
                            {--context=* : Context values in format key=value}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     *
     * @throws ClientThrowable
     * @throws InvalidArgumentException
     */
    public function handle(OpenFgaManager $manager): int
    {
        $userArg = $this->argument('user');
        $relationArg = $this->argument('relation');
        $objectArg = $this->argument('object');

        if (! is_string($userArg) || ! is_string($relationArg) || ! is_string($objectArg)) {
            $this->error('Invalid arguments: user, relation, and object must be strings');

            return Command::FAILURE;
        }

        $user = $userArg;
        $relation = $relationArg;
        $object = $objectArg;
        $connection = $this->option('connection');
        $connection = is_string($connection) ? $connection : null;

        // Parse contextual tuples
        $ctOption = $this->option('contextual-tuple');
        $ctOptionArray = is_array($ctOption) ? array_filter($ctOption, 'is_string') : [];
        $contextualTuples = $this->parseContextualTuples($ctOptionArray);

        // Parse context
        $ctxOption = $this->option('context');
        $ctxOptionArray = is_array($ctxOption) ? array_filter($ctxOption, 'is_string') : [];
        $context = $this->parseContext($ctxOptionArray);

        try {
            $startTime = microtime(true);

            $allowed = $manager->check($user, $relation, $object, $contextualTuples, $context, $connection);

            $duration = round((microtime(true) - $startTime) * 1000.0, 2);

            if (true === $this->option('json')) {
                $jsonOutput = json_encode([
                    'allowed' => $allowed,
                    'user' => $user,
                    'relation' => $relation,
                    'object' => $object,
                    'duration_ms' => $duration,
                    'connection' => $connection ?? 'default',
                ], JSON_PRETTY_PRINT);
                $this->output->writeln(false !== $jsonOutput ? $jsonOutput : '');
            } else {
                if ($allowed) {
                    $this->info('✅ Permission granted');
                } else {
                    $this->error('❌ Permission denied');
                }

                $this->table(
                    ['Field', 'Value'],
                    [
                        ['User', $user],
                        ['Relation', $relation],
                        ['Object', $object],
                        ['Connection', $connection ?? 'default'],
                        ['Duration', ((string) $duration) . 'ms'],
                    ],
                );

                if ([] !== $contextualTuples) {
                    $this->info("\nContextual Tuples:");

                    foreach ($contextualTuples as $contextualTuple) {
                        $this->line(sprintf('  - %s#%s@%s', $contextualTuple['user'], $contextualTuple['relation'], $contextualTuple['object']));
                    }
                }

                if ([] !== $context) {
                    $this->info("\nContext:");

                    foreach ($context as $key => $value) {
                        $this->line(sprintf('  - %s: %s', $key, $value));
                    }
                }
            }

            return $allowed ? Command::SUCCESS : Command::FAILURE;
        } catch (Exception $exception) {
            if (true === $this->option('json')) {
                $errorJson = json_encode([
                    'error' => true,
                    'message' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT);
                $this->output->writeln(false !== $errorJson ? $errorJson : '');
            } else {
                $this->error('Error: ' . $exception->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Parse context values from command options.
     *
     * @param  array<string>         $contextValues
     * @return array<string, string>
     */
    private function parseContext(array $contextValues): array
    {
        $context = [];

        foreach ($contextValues as $contextValue) {
            $parts = explode('=', $contextValue, 2);

            if (2 !== count($parts)) {
                $this->warn(sprintf('Invalid context format: %s. Expected format: key=value', $contextValue));

                continue;
            }

            $context[$parts[0]] = $parts[1];
        }

        return $context;
    }

    /**
     * Parse contextual tuples from command options.
     *
     * @param  array<string>                                                $tuples
     * @return array<array{user: string, relation: string, object: string}>
     */
    private function parseContextualTuples(array $tuples): array
    {
        $parsed = [];

        foreach ($tuples as $tuple) {
            $parts = explode(':', $tuple, 3);

            if (3 !== count($parts)) {
                $this->warn(sprintf('Invalid contextual tuple format: %s. Expected format: user:relation:object', $tuple));

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
}
