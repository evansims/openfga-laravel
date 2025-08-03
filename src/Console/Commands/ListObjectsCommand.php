<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\OpenFgaManager;

use function array_slice;
use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Command to list objects that a user has a specific relation to.
 */
final class ListObjectsCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List objects that a user has a specific relation to';

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
        $typeArg = $this->argument('type');

        if (! is_string($userArg) || ! is_string($relationArg) || ! is_string($typeArg)) {
            $this->error('Invalid arguments provided');

            return Command::FAILURE;
        }

        $user = $userArg;
        $relation = $relationArg;
        $type = $typeArg;
        $connection = $this->option('connection');
        $connection = is_string($connection) ? $connection : null;

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 100;

        // Parse contextual tuples
        $contextualTupleOption = $this->option('contextual-tuple');
        $ctOptionArray = is_array($contextualTupleOption) ? array_filter($contextualTupleOption, 'is_string') : [];
        $contextualTuples = $this->parseContextualTuples($ctOptionArray);

        // Parse context
        $contextOption = $this->option('context');
        $ctxOptionArray = is_array($contextOption) ? array_filter($contextOption, 'is_string') : [];
        $context = $this->parseContext($ctxOptionArray);

        try {
            $startTime = microtime(true);

            $objects = $manager->listObjects($user, $relation, $type, $contextualTuples, $context, $connection);

            $duration = round((microtime(true) - $startTime) * 1000.0, 2);

            // Apply limit if specified

            if (0 < $limit && count($objects) > $limit) {
                $truncated = true;
                $objects = array_slice($objects, 0, $limit);
            } else {
                $truncated = false;
            }

            if (true === $this->option('json')) {
                $jsonOutput = json_encode([
                    'success' => true,
                    'user' => $user,
                    'relation' => $relation,
                    'type' => $type,
                    'objects' => $objects,
                    'count' => count($objects),
                    'truncated' => $truncated,
                    'duration_ms' => $duration,
                    'connection' => $connection ?? 'default',
                ], JSON_PRETTY_PRINT);

                if (false !== $jsonOutput) {
                    $this->output->writeln($jsonOutput);
                }
            } else {
                $this->displayResults($objects, $user, $relation, $type, $duration, $truncated);

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

            return Command::SUCCESS;
        } catch (Exception $exception) {
            if (true === $this->option('json')) {
                $jsonError = json_encode([
                    'error' => true,
                    'message' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT);

                if (false !== $jsonError) {
                    $this->output->writeln($jsonError);
                }
            } else {
                $this->error('Error: ' . $exception->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Display the results in a formatted table.
     *
     * @param array<string> $objects
     * @param string        $user
     * @param string        $relation
     * @param string        $type
     * @param float         $duration
     * @param bool          $truncated
     */
    private function displayResults(
        array $objects,
        string $user,
        string $relation,
        string $type,
        float $duration,
        bool $truncated,
    ): void {
        if ([] === $objects) {
            $this->warn(sprintf('No objects found where %s has %s permission on type %s', $user, $relation, $type));

            return;
        }

        $this->info(sprintf('Objects where %s has %s permission:', $user, $relation));
        $this->newLine();

        // Group objects by type prefix if they have different types
        $grouped = $this->groupObjectsByType($objects);

        if (1 === count($grouped)) {
            // All objects are of the same type
            foreach ($objects as $object) {
                $this->line('  - ' . $object);
            }
        } else {
            // Objects have different types, group them
            foreach ($grouped as $objectType => $objectList) {
                $this->info(sprintf('  %s:', $objectType));

                foreach ($objectList as $object) {
                    $this->line('    - ' . $object);
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Objects', count($objects) . ($truncated ? ' (truncated)' : '')],
                ['Duration', ((string) $duration) . 'ms'],
                ['Connection', $this->option('connection') ?? 'default'],
            ],
        );
    }

    /**
     * Group objects by their type prefix.
     *
     * @param  array<string>                $objects
     * @return array<string, array<string>>
     */
    private function groupObjectsByType(array $objects): array
    {
        $grouped = [];

        foreach ($objects as $object) {
            $parts = explode(':', $object, 2);
            $type = 2 === count($parts) ? $parts[0] : 'unknown';

            if (! isset($grouped[$type])) {
                $grouped[$type] = [];
            }

            $grouped[$type][] = $object;
        }

        return $grouped;
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
