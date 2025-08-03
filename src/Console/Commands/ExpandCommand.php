<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
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
     * @var string
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
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
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

            $result = $manager->expand($relation, $object, $connection);

            $duration = round((microtime(true) - $startTime) * 1000.0, 2);

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

                {
                    $treeOption = $this->option('tree');

                    if (true === $treeOption) {
                        $this->displayTree($result);
                    } else {
                        $this->displaySimple($result);
                    }
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
            $nodeLeafUsers = $node['leaf']['users'];

            /** @var mixed $nodeLeafUser */
            foreach ($nodeLeafUsers as $nodeLeafUser) {
                if (is_string($nodeLeafUser)) {
                    $users[] = $nodeLeafUser;
                }
            }
        }

        if (isset($node['union']) && is_array($node['union']) && isset($node['union']['nodes']) && is_array($node['union']['nodes'])) {
            $nodeUnionNodes = $node['union']['nodes'];

            /** @var mixed $nodeUnionNode */
            foreach ($nodeUnionNodes as $nodeUnionNode) {
                if (is_array($nodeUnionNode)) {
                    $this->collectUsers($nodeUnionNode, $users);
                }
            }
        }

        if (isset($node['intersection']) && is_array($node['intersection']) && isset($node['intersection']['nodes']) && is_array($node['intersection']['nodes'])) {
            $nodeIntersectionNodes = $node['intersection']['nodes'];

            /** @var mixed $nodeIntersectionNode */
            foreach ($nodeIntersectionNodes as $nodeIntersectionNode) {
                if (is_array($nodeIntersectionNode)) {
                    $this->collectUsers($nodeIntersectionNode, $users);
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
            $this->line('  - ' . $user);
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

            $unionNodes = $node['union']['nodes'];

            /** @var mixed $unionNode */
            foreach ($unionNodes as $unionNode) {
                if (is_array($unionNode)) {
                    $this->renderTreeNode($unionNode, $depth + 2);
                }
            }
        }

        if (isset($node['intersection']) && is_array($node['intersection']) && isset($node['intersection']['nodes']) && is_array($node['intersection']['nodes'])) {
            $this->line($indent . '  ∩ Intersection:');

            $intersectionNodes = $node['intersection']['nodes'];

            /** @var mixed $intersectionNode */
            foreach ($intersectionNodes as $intersectionNode) {
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

                /** @var array<mixed> $leafUsers */
                $leafUsers = $node['leaf']['users'];

                /** @var mixed $leafUser */
                foreach ($leafUsers as $leafUser) {
                    if (is_string($leafUser) || is_numeric($leafUser)) {
                        $this->line($indent . '    - ' . (string) $leafUser);
                    }
                }
            }

            if (isset($node['leaf']['computed']) && is_array($node['leaf']['computed']) && [] !== $node['leaf']['computed']) {
                $this->line($indent . '  🔗 Computed:');

                /** @var array<mixed> $computedItems */
                $computedItems = $node['leaf']['computed'];

                /** @var mixed $computedItem */
                foreach ($computedItems as $computedItem) {
                    if (is_array($computedItem)) {
                        if (isset($computedItem['userset']) && (is_string($computedItem['userset']) || is_numeric($computedItem['userset']))) {
                            $this->line($indent . '    - ' . (string) $computedItem['userset']);
                        }

                        if (isset($computedItem['relation']) && (is_string($computedItem['relation']) || is_numeric($computedItem['relation']))) {
                            $this->line($indent . '    - ' . (string) $computedItem['relation']);
                        }
                    }
                }
            }

            if (isset($node['leaf']['tupleToUserset']) && is_array($node['leaf']['tupleToUserset']) && [] !== $node['leaf']['tupleToUserset']) {
                $this->line($indent . '  🔄 Tuple to Userset:');

                /** @var array<mixed> $tupleToUsersetItems */
                $tupleToUsersetItems = $node['leaf']['tupleToUserset'];

                /** @var mixed $tupleToUsersetItem */
                foreach ($tupleToUsersetItems as $tupleToUsersetItem) {
                    if (is_array($tupleToUsersetItem) && isset($tupleToUsersetItem['tupleset'], $tupleToUsersetItem['computedUserset']) && is_array($tupleToUsersetItem['computedUserset']) && isset($tupleToUsersetItem['computedUserset']['relation'])) {
                        $tuplesetStr = is_string($tupleToUsersetItem['tupleset']) || is_numeric($tupleToUsersetItem['tupleset']) ? (string) $tupleToUsersetItem['tupleset'] : '';
                        $relationStr = is_string($tupleToUsersetItem['computedUserset']['relation']) || is_numeric($tupleToUsersetItem['computedUserset']['relation']) ? (string) $tupleToUsersetItem['computedUserset']['relation'] : '';

                        if ('' !== $tuplesetStr && '' !== $relationStr) {
                            $this->line($indent . '    - ' . $tuplesetStr . ' → ' . $relationStr);
                        }
                    }
                }
            }
        }
    }
}
