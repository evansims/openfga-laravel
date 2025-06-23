<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Command to expand a relation and see all users who have it.
 */
class ExpandCommand extends Command
{
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
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expand a relation to see all users who have that permission';

    /**
     * Execute the console command.
     */
    public function handle(OpenFgaManager $manager): int
    {
        $object = $this->argument('object');
        $relation = $this->argument('relation');
        $connection = $this->option('connection');

        try {
            $startTime = microtime(true);
            
            $result = $manager
                ->connection($connection)
                ->expand($object, $relation);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($this->option('json')) {
                $this->output->writeln(json_encode([
                    'success' => true,
                    'object' => $object,
                    'relation' => $relation,
                    'result' => $result,
                    'duration_ms' => $duration,
                    'connection' => $connection ?? 'default',
                ], JSON_PRETTY_PRINT));
            } else {
                $this->info("Expansion Results for {$relation} on {$object}");
                $this->newLine();

                if ($this->option('tree')) {
                    $this->displayTree($result);
                } else {
                    $this->displaySimple($result);
                }

                $this->newLine();
                $this->line("Duration: {$duration}ms");
                $this->line("Connection: " . ($connection ?? 'default'));
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
     * Display the expansion result in a simple format.
     */
    protected function displaySimple(array $result): void
    {
        $users = $this->extractUsers($result);
        
        if (empty($users)) {
            $this->warn("No users found with this permission");
            return;
        }

        $this->info("Users with permission:");
        foreach ($users as $user) {
            $this->line("  - {$user}");
        }
        
        $this->newLine();
        $this->line("Total: " . count($users) . " user(s)");
    }

    /**
     * Display the expansion result as a tree.
     */
    protected function displayTree(array $result): void
    {
        if (isset($result['tree'])) {
            $this->renderTreeNode($result['tree'], 0);
        } else {
            $this->warn("No tree structure available");
        }
    }

    /**
     * Recursively render a tree node.
     */
    protected function renderTreeNode(array $node, int $depth = 0): void
    {
        $indent = str_repeat('  ', $depth);

        if (isset($node['root'])) {
            $this->renderTreeNode($node['root'], $depth);
            return;
        }

        if (isset($node['name'])) {
            $this->line($indent . "📁 " . $node['name']);
        }

        if (isset($node['union'])) {
            $this->line($indent . "  ∪ Union:");
            foreach ($node['union']['nodes'] as $unionNode) {
                $this->renderTreeNode($unionNode, $depth + 2);
            }
        }

        if (isset($node['intersection'])) {
            $this->line($indent . "  ∩ Intersection:");
            foreach ($node['intersection']['nodes'] as $intersectionNode) {
                $this->renderTreeNode($intersectionNode, $depth + 2);
            }
        }

        if (isset($node['difference'])) {
            $this->line($indent . "  − Difference:");
            $this->line($indent . "    Base:");
            $this->renderTreeNode($node['difference']['base'], $depth + 3);
            $this->line($indent . "    Subtract:");
            $this->renderTreeNode($node['difference']['subtract'], $depth + 3);
        }

        if (isset($node['leaf'])) {
            if (isset($node['leaf']['users']) && !empty($node['leaf']['users'])) {
                $this->line($indent . "  👤 Users:");
                foreach ($node['leaf']['users'] as $user) {
                    $this->line($indent . "    - " . $user);
                }
            }

            if (isset($node['leaf']['computed']) && !empty($node['leaf']['computed'])) {
                $this->line($indent . "  🔗 Computed:");
                foreach ($node['leaf']['computed'] as $computed) {
                    if (isset($computed['userset'])) {
                        $this->line($indent . "    - " . $computed['userset']);
                    }
                    if (isset($computed['relation'])) {
                        $this->line($indent . "    - " . $computed['relation']);
                    }
                }
            }

            if (isset($node['leaf']['tupleToUserset']) && !empty($node['leaf']['tupleToUserset'])) {
                $this->line($indent . "  🔄 Tuple to Userset:");
                foreach ($node['leaf']['tupleToUserset'] as $ttu) {
                    if (isset($ttu['tupleset'], $ttu['computedUserset']['relation'])) {
                        $this->line($indent . "    - " . $ttu['tupleset'] . " → " . $ttu['computedUserset']['relation']);
                    }
                }
            }
        }
    }

    /**
     * Extract all users from the expansion result.
     *
     * @return array<string>
     */
    protected function extractUsers(array $result): array
    {
        $users = [];
        $this->collectUsers($result, $users);
        return array_unique($users);
    }

    /**
     * Recursively collect users from the tree.
     */
    protected function collectUsers(array $node, array &$users): void
    {
        if (isset($node['tree'])) {
            $this->collectUsers($node['tree'], $users);
        }

        if (isset($node['root'])) {
            $this->collectUsers($node['root'], $users);
        }

        if (isset($node['leaf']['users'])) {
            foreach ($node['leaf']['users'] as $user) {
                $users[] = $user;
            }
        }

        if (isset($node['union']['nodes'])) {
            foreach ($node['union']['nodes'] as $unionNode) {
                $this->collectUsers($unionNode, $users);
            }
        }

        if (isset($node['intersection']['nodes'])) {
            foreach ($node['intersection']['nodes'] as $intersectionNode) {
                $this->collectUsers($intersectionNode, $users);
            }
        }

        if (isset($node['difference'])) {
            $this->collectUsers($node['difference']['base'], $users);
            // Note: We don't collect from 'subtract' as those are excluded
        }
    }
}