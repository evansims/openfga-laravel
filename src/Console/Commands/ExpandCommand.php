<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Command to expand a relation and see all users who have it.
 */
final class ExpandCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Expand a relation to see all users who have that permission';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:expand {object} {relation}
                            {--connection= : The connection to use}
                            {--json : Output as JSON}
                            {--tree : Show full tree structure}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     */
    public function handle(OpenFgaManager $manager): int
    {
        $objectArg = $this->argument('object');
        $relationArg = $this->argument('relation');
        $connectionArg = $this->option('connection');

        if (! is_string($objectArg) || ! is_string($relationArg)) {
            $this->error('Invalid arguments: object and relation must be strings');

            return Command::FAILURE;
        }

        $object = $objectArg;
        $relation = $relationArg;
        $connection = is_string($connectionArg) ? $connectionArg : null;

        try {
            $startTime = microtime(true);

            /**
             * @var array<string, mixed>
             *
             * @phpstan-ignore-next-line
             */
            $result = $manager
                ->connection($connection)
                ->expand($object, $relation);

            $duration = round((float) ((microtime(true) - $startTime) * 1000), 2);

            $jsonOption = $this->option('json');

            if (true === $jsonOption) {
                $jsonString = json_encode([
                    'success' => true,
                    'object' => $object,
                    'relation' => $relation,
                    'result' => $result,
                    'duration_ms' => $duration,
                    'connection' => $connection ?? 'default',
                ], JSON_PRETTY_PRINT);

                if (false !== $jsonString) {
                    $this->output->writeln($jsonString);
                }
            } else {
                $this->info(sprintf('Expansion Results for %s on %s', $relation, $object));
                $this->newLine();

                if (is_array($result)) {
                    $treeOption = $this->option('tree');

                    if (true === $treeOption) {
                        $this->displayTree($result);
                    } else {
                        $this->displaySimple($result);
                    }
                } else {
                    $this->error('Unexpected result format from expand operation');
                }

                $this->newLine();
                $this->line(sprintf('Duration: %sms', $duration));
                $this->line('Connection: ' . ($connection ?? 'default'));
            }

            return Command::SUCCESS;
        } catch (Exception $exception) {
            $jsonOption = $this->option('json');

            if (true === $jsonOption) {
                $jsonString = json_encode([
                    'error' => true,
                    'message' => $exception->getMessage(),
                ], JSON_PRETTY_PRINT);

                if (false !== $jsonString) {
                    $this->output->writeln($jsonString);
                }
            } else {
                $this->error('Error: ' . $exception->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Recursively collect users from the tree.
     *
     * @param array<mixed, mixed> $node
     * @param array<int, string>  $users
     */
    private function collectUsers(array $node, array &$users): void
    {
        if (isset($node['tree']) && is_array($node['tree'])) {
            $this->collectUsers($node['tree'], $users);
        }

        if (isset($node['root']) && is_array($node['root'])) {
            $this->collectUsers($node['root'], $users);
        }

        if (isset($node['leaf']) && is_array($node['leaf']) && isset($node['leaf']['users']) && is_array($node['leaf']['users'])) {
            foreach ($node['leaf']['users'] as $user) {
                if (is_string($user)) {
                    $users[] = $user;
                }
            }
        }

        if (isset($node['union']) && is_array($node['union']) && isset($node['union']['nodes']) && is_array($node['union']['nodes'])) {
            foreach ($node['union']['nodes'] as $unionNode) {
                if (is_array($unionNode)) {
                    $this->collectUsers($unionNode, $users);
                }
            }
        }

        if (isset($node['intersection']) && is_array($node['intersection']) && isset($node['intersection']['nodes']) && is_array($node['intersection']['nodes'])) {
            foreach ($node['intersection']['nodes'] as $intersectionNode) {
                if (is_array($intersectionNode)) {
                    $this->collectUsers($intersectionNode, $users);
                }
            }
        }

        if (isset($node['difference']) && is_array($node['difference']) && isset($node['difference']['base']) && is_array($node['difference']['base'])) {
            $this->collectUsers($node['difference']['base'], $users);
            // Note: We don't collect from 'subtract' as those are excluded
        }
    }

    /**
     * Display the expansion result in a simple format.
     *
     * @param array<string, mixed> $result
     */
    private function displaySimple(array $result): void
    {
        $users = $this->extractUsers($result);

        if ([] === $users) {
            $this->warn('No users found with this permission');

            return;
        }

        $this->info('Users with permission:');

        foreach ($users as $user) {
            $this->line('  - ' . (string) $user);
        }

        $this->newLine();
        $this->line('Total: ' . count($users) . ' user(s)');
    }

    /**
     * Display the expansion result as a tree.
     *
     * @param array<string, mixed> $result
     */
    private function displayTree(array $result): void
    {
        if (isset($result['tree']) && is_array($result['tree'])) {
            $this->renderTreeNode($result['tree'], 0);
        } else {
            $this->warn('No tree structure available');
        }
    }

    /**
     * Extract all users from the expansion result.
     *
     * @param  array<string, mixed> $result
     * @return array<int, string>
     */
    private function extractUsers(array $result): array
    {
        $users = [];
        $this->collectUsers($result, $users);

        return array_unique($users);
    }

    /**
     * Recursively render a tree node.
     *
     * @param array<mixed, mixed> $node
     * @param int                 $depth
     */
    private function renderTreeNode(array $node, int $depth = 0): void
    {
        $indent = str_repeat('  ', $depth);

        if (isset($node['root']) && is_array($node['root'])) {
            $this->renderTreeNode($node['root'], $depth);

            return;
        }

        if (isset($node['name']) && (is_string($node['name']) || is_numeric($node['name']))) {
            $this->line($indent . '📁 ' . (string) $node['name']);
        }

        if (isset($node['union']) && is_array($node['union']) && isset($node['union']['nodes']) && is_array($node['union']['nodes'])) {
            $this->line($indent . '  ∪ Union:');

            foreach ($node['union']['nodes'] as $unionNode) {
                if (is_array($unionNode)) {
                    $this->renderTreeNode($unionNode, $depth + 2);
                }
            }
        }

        if (isset($node['intersection']) && is_array($node['intersection']) && isset($node['intersection']['nodes']) && is_array($node['intersection']['nodes'])) {
            $this->line($indent . '  ∩ Intersection:');

            foreach ($node['intersection']['nodes'] as $intersectionNode) {
                if (is_array($intersectionNode)) {
                    $this->renderTreeNode($intersectionNode, $depth + 2);
                }
            }
        }

        if (isset($node['difference']) && is_array($node['difference'])) {
            $this->line($indent . '  − Difference:');

            if (isset($node['difference']['base']) && is_array($node['difference']['base'])) {
                $this->line($indent . '    Base:');
                $this->renderTreeNode($node['difference']['base'], $depth + 3);
            }

            if (isset($node['difference']['subtract']) && is_array($node['difference']['subtract'])) {
                $this->line($indent . '    Subtract:');
                $this->renderTreeNode($node['difference']['subtract'], $depth + 3);
            }
        }

        if (isset($node['leaf']) && is_array($node['leaf'])) {
            if (isset($node['leaf']['users']) && is_array($node['leaf']['users']) && [] !== $node['leaf']['users']) {
                $this->line($indent . '  👤 Users:');

                foreach ($node['leaf']['users'] as $user) {
                    if (is_string($user) || is_numeric($user)) {
                        $this->line($indent . '    - ' . (string) $user);
                    }
                }
            }

            if (isset($node['leaf']['computed']) && is_array($node['leaf']['computed']) && [] !== $node['leaf']['computed']) {
                $this->line($indent . '  🔗 Computed:');

                foreach ($node['leaf']['computed'] as $computed) {
                    if (is_array($computed)) {
                        if (isset($computed['userset']) && (is_string($computed['userset']) || is_numeric($computed['userset']))) {
                            $this->line($indent . '    - ' . (string) $computed['userset']);
                        }

                        if (isset($computed['relation']) && (is_string($computed['relation']) || is_numeric($computed['relation']))) {
                            $this->line($indent . '    - ' . (string) $computed['relation']);
                        }
                    }
                }
            }

            if (isset($node['leaf']['tupleToUserset']) && is_array($node['leaf']['tupleToUserset']) && [] !== $node['leaf']['tupleToUserset']) {
                $this->line($indent . '  🔄 Tuple to Userset:');

                foreach ($node['leaf']['tupleToUserset'] as $ttu) {
                    if (is_array($ttu) && isset($ttu['tupleset'], $ttu['computedUserset']) && is_array($ttu['computedUserset']) && isset($ttu['computedUserset']['relation'])) {
                        $tuplesetStr = is_string($ttu['tupleset']) || is_numeric($ttu['tupleset']) ? (string) $ttu['tupleset'] : '';
                        $relationStr = is_string($ttu['computedUserset']['relation']) || is_numeric($ttu['computedUserset']['relation']) ? (string) $ttu['computedUserset']['relation'] : '';

                        if ('' !== $tuplesetStr && '' !== $relationStr) {
                            $this->line($indent . '    - ' . $tuplesetStr . ' → ' . $relationStr);
                        }
                    }
                }
            }
        }
    }
}
