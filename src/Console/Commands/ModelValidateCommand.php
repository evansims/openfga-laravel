<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

use function in_array;
use function sprintf;

final class ModelValidateCommand extends Command
{
    private const array RESERVED_RELATIONS = ['self'];

    /**
     * DSL validation rules.
     */
    private const array VALID_SCHEMA_VERSIONS = ['1.1'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate an OpenFGA model DSL file';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'openfga:model:validate
                            {--file= : Path to DSL file to validate}
                            {--json : Output validation results as JSON}
                            {--create : Create the model in OpenFGA after validation}
                            {--connection= : The OpenFGA connection to use}';

    /**
     * Execute the console command.
     *
     * @param OpenFgaManager $manager
     */
    public function handle(OpenFgaManager $manager): int
    {
        $filePath = $this->option('file');
        $this->option('connection');

        if (! $filePath) {
            $this->error('Please specify a file to validate using --file option');

            return self::FAILURE;
        }

        if (! file_exists($filePath)) {
            $this->error('File not found: ' . $filePath);

            return self::FAILURE;
        }

        $dsl = file_get_contents($filePath);
        $errors = $this->validateDsl($dsl);

        if ($this->option('json')) {
            $this->outputJson($errors);
        } else {
            $this->outputTable($errors, $filePath);
        }

        if ([] !== $errors) {
            return self::FAILURE;
        }

        $this->info('✅ Model validation passed!');

        // Create model if requested
        if ($this->option('create')) {
            return $this->createModel();
        }

        return self::SUCCESS;
    }

    /**
     * Create model in OpenFGA.
     */
    private function createModel(): int
    {
        try {
            $this->info('Creating model in OpenFGA...');

            // Note: Actual implementation would use the OpenFGA client
            // to create the model via the API

            $this->warn('Note: Actual model creation requires OpenFGA API integration.');
            $this->info('The validated model can be uploaded to OpenFGA using the dashboard or API.');

            return self::SUCCESS;
        } catch (Exception $exception) {
            $this->error('Failed to create model: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Extract referenced types from a relation definition.
     *
     * @param string $definition
     * @param array  $referencedTypes
     */
    private function extractReferencedTypes(string $definition, array &$referencedTypes): void
    {
        // Match type references like [user], [group#member], etc.
        preg_match_all('/\[([a-z][a-z0-9_]*)(?:#[a-z][a-z0-9_]*)?\]/', $definition, $matches);

        foreach ($matches[1] as $type) {
            $referencedTypes[] = $type;
        }

        // Match references in "from" clauses
        preg_match_all('/from\s+([a-z][a-z0-9_]*)/', $definition, $matches);

        foreach ($matches[1] as $type) {
            $referencedTypes[] = $type;
        }
    }

    /**
     * Output validation results as JSON.
     *
     * @param array $errors
     */
    private function outputJson(array $errors): void
    {
        $output = [
            'valid' => [] === $errors,
            'errors' => $errors,
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    /**
     * Output validation results as table.
     *
     * @param array  $errors
     * @param string $filePath
     */
    private function outputTable(array $errors, string $filePath): void
    {
        if ([] === $errors) {
            return;
        }

        $this->error('Validation failed for: ' . $filePath);
        $this->newLine();

        $headers = ['Line', 'Type', 'Error'];
        $rows = array_map(static fn ($error): array => [
            $error['line'] ?: 'N/A',
            $error['type'],
            $error['message'],
        ], $errors);

        $this->table($headers, $rows);
    }

    /**
     * Validate DSL content.
     *
     * @param string $dsl
     */
    private function validateDsl(string $dsl): array
    {
        $errors = [];
        $lines = explode("\n", $dsl);
        $currentType = null;
        $inRelationsBlock = false;
        $definedTypes = [];
        $referencedTypes = [];
        $lineNumber = 0;

        // Check for model declaration
        if (! preg_match('/^model\s*$/', trim($lines[0] ?? ''))) {
            $errors[] = [
                'line' => 1,
                'type' => 'syntax',
                'message' => 'Model must start with "model" declaration',
            ];
        }

        // Check for schema version
        if (! preg_match('/^\s*schema\s+(' . implode('|', self::VALID_SCHEMA_VERSIONS) . ')\s*$/', $lines[1] ?? '')) {
            $errors[] = [
                'line' => 2,
                'type' => 'schema',
                'message' => 'Invalid or missing schema version. Valid versions: ' . implode(', ', self::VALID_SCHEMA_VERSIONS),
            ];
        }

        // Parse types and relations
        foreach ($lines as $lineNumber => $line) {
            ++$lineNumber; // 1-indexed
            $trimmedLine = trim($line);

            if (empty($trimmedLine)) {
                continue;
            }

            if ('model' === $trimmedLine) {
                continue;
            }

            if (str_starts_with($trimmedLine, 'schema')) {
                continue;
            }

            // Type declaration
            if (preg_match('/^type\s+([a-z][a-z0-9_]*)\s*$/', $trimmedLine, $matches)) {
                $currentType = $matches[1];
                $definedTypes[] = $currentType;
                $inRelationsBlock = false;

                continue;
            }

            // Relations block
            if ('relations' === $trimmedLine && $currentType) {
                $inRelationsBlock = true;

                continue;
            }

            // Relation definition
            if ($inRelationsBlock && preg_match('/^define\s+([a-z][a-z0-9_]*)\s*:\s*(.+)$/', $trimmedLine, $matches)) {
                $relationName = $matches[1];
                $definition = $matches[2];

                // Validate relation name
                if (in_array($relationName, self::RESERVED_RELATIONS, true)) {
                    $errors[] = [
                        'line' => $lineNumber,
                        'type' => 'relation',
                        'message' => 'Reserved relation name: ' . $relationName,
                    ];
                }

                // Extract referenced types from definition
                $this->extractReferencedTypes($definition, $referencedTypes);

                // Validate definition syntax
                $definitionErrors = $this->validateRelationDefinition($definition, $lineNumber);
                $errors = array_merge($errors, $definitionErrors);
            }
        }

        // Check for undefined type references
        $undefinedTypes = array_diff(array_unique($referencedTypes), $definedTypes);

        foreach ($undefinedTypes as $undefinedType) {
            $errors[] = [
                'line' => 0,
                'type' => 'reference',
                'message' => sprintf("Referenced type '%s' is not defined", $undefinedType),
            ];
        }

        return $errors;
    }

    /**
     * Validate relation definition syntax.
     *
     * @param string $definition
     * @param int    $lineNumber
     */
    private function validateRelationDefinition(string $definition, int $lineNumber): array
    {
        $errors = [];

        // Check for balanced brackets
        $openBrackets = substr_count($definition, '[');
        $closeBrackets = substr_count($definition, ']');

        if ($openBrackets !== $closeBrackets) {
            $errors[] = [
                'line' => $lineNumber,
                'type' => 'syntax',
                'message' => 'Unbalanced brackets in relation definition',
            ];
        }

        // Basic syntax validation
        if (! preg_match('/^\[.+\]/', $definition) && ! str_contains($definition, ' or ') && ! str_contains($definition, ' and ')) {
            $errors[] = [
                'line' => $lineNumber,
                'type' => 'syntax',
                'message' => 'Invalid relation definition syntax',
            ];
        }

        return $errors;
    }
}
