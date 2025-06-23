<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;
use Symfony\Component\Console\Input\InputOption;

/**
 * Command to check if a user has a specific permission.
 */
class CheckCommand extends Command
{
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
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if a user has a specific permission on an object';

    /**
     * Execute the console command.
     */
    public function handle(OpenFgaManager $manager): int
    {
        $user = $this->argument('user');
        $relation = $this->argument('relation');
        $object = $this->argument('object');
        $connection = $this->option('connection');

        // Parse contextual tuples
        $contextualTuples = $this->parseContextualTuples($this->option('contextual-tuple'));
        
        // Parse context
        $context = $this->parseContext($this->option('context'));

        try {
            $startTime = microtime(true);
            
            $allowed = $manager
                ->connection($connection)
                ->check($user, $relation, $object, $contextualTuples, $context);
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($this->option('json')) {
                $this->output->writeln(json_encode([
                    'allowed' => $allowed,
                    'user' => $user,
                    'relation' => $relation,
                    'object' => $object,
                    'duration_ms' => $duration,
                    'connection' => $connection ?? 'default',
                ], JSON_PRETTY_PRINT));
            } else {
                if ($allowed) {
                    $this->info("✅ Permission granted");
                } else {
                    $this->error("❌ Permission denied");
                }
                
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['User', $user],
                        ['Relation', $relation],
                        ['Object', $object],
                        ['Connection', $connection ?? 'default'],
                        ['Duration', "{$duration}ms"],
                    ]
                );

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

            return $allowed ? Command::SUCCESS : Command::FAILURE;
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