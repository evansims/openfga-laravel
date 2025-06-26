<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use OpenFGA\Laravel\OpenFgaManager;

use function in_array;
use function is_string;
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
     * @var string|null
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

        if (! is_string($filePath) || '' === $filePath) {
            $this->error('Please specify a file to validate using --file option');

            return self::FAILURE;
        }

        if (! file_exists($filePath)) {
            $this->error('File not found: ' . $filePath);

            return self::FAILURE;
        }

        $dsl = file_get_contents($filePath);

        if (false === $dsl) {
            $this->error('Failed to read file: ' . $filePath);

            return self::FAILURE;
        }

        $errors = $this->validateDsl($dsl);

        if ((bool) $this->option('json')) {
            $this->outputJson($errors);
        } else {
            $this->outputTable($errors, $filePath);
        }

        if ([] !== $errors) {
            return self::FAILURE;
        }

        if (! (bool) $this->option('json')) {
            $this->info('✅ Model validation passed!');
        }

        // Create model if requested
        if ((bool) $this->option('create')) {
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
     * @param string             $definition
     * @param array<int, string> $referencedTypes
     */
    private function extractReferencedTypes(string $definition, array &$referencedTypes): void
    {
        // Match type references like [user], [group#member], etc.
        $matches = [];

        // When not using PREG_SET_ORDER, capture groups are in $matches[1]
        if (0 < preg_match_all('/\[([a-z][a-z0-9_]*)(?:#[a-z][a-z0-9_]*)?\]/', $definition, $matches) && isset($matches[1])) {
            /** @var array<int, string> $types */
            $types = $matches[1];

            foreach ($types as $type) {
                $referencedTypes[] = $type;
            }
        }

        // Match references in "from" clauses
        $matches = [];

        // When not using PREG_SET_ORDER, capture groups are in $matches[1]
        if (0 < preg_match_all('/from\s+([a-z][a-z0-9_]*)/', $definition, $matches) && isset($matches[1])) {
            /** @var array<int, string> $types */
            $types = $matches[1];

            foreach ($types as $type) {
                $referencedTypes[] = $type;
            }
        }
    }

    /**
     * Output validation results as JSON.
     *
     * @param array<int, array{line: int|null, type: string, message: string}> $errors
     */
    private function outputJson(array $errors): void
    {
        $output = [
            'valid' => [] === $errors,
            'errors' => $errors,
        ];

        $encoded = json_encode($output, JSON_PRETTY_PRINT);

        if (false !== $encoded) {
            $this->line($encoded);
        }
    }

    /**
     * Output validation results as table.
     *
     * @param array<int, array{line: int|null, type: string, message: string}> $errors
     * @param string                                                           $filePath
     */
    private function outputTable(array $errors, string $filePath): void
    {
        if ([] === $errors) {
            return;
        }

        $this->error('Validation failed for: ' . $filePath);
        $this->newLine();

        $headers = ['Line', 'Type', 'Error'];
        $rows = array_map(static fn (array $error): array => [
            null !== $error['line'] ? (string) $error['line'] : 'N/A',
            $error['type'],
            $error['message'],
        ], $errors);

        $this->table($headers, $rows);
    }

    /**
     * Validate DSL content.
     *
     * @param  string                                                           $dsl
     * @return array<int, array{line: int|null, type: string, message: string}>
     */
    private function validateDsl(string $dsl): array
    {
        /** @var array<int, array{line: int|null, type: string, message: string}> $errors */
        $errors = [];
        $lines = explode("\n", $dsl);
        $currentType = null;
        $inRelationsBlock = false;

        /** @var array<int, string> $definedTypes */
        $definedTypes = [];

        /** @var array<int, string> $referencedTypes */
        $referencedTypes = [];

        // Check for model declaration
        if (1 !== preg_match('/^model\s*$/', trim($lines[0] ?? ''))) {
            $errors[] = [
                'line' => 1,
                'type' => 'syntax',
                'message' => 'Model must start with "model" declaration',
            ];
        }

        // Check for schema version
        if (1 !== preg_match('/^\s*schema\s+(' . implode('|', self::VALID_SCHEMA_VERSIONS) . ')\s*$/', $lines[1] ?? '')) {
            $errors[] = [
                'line' => 2,
                'type' => 'schema',
                'message' => 'Invalid or missing schema version. Valid versions: ' . implode(', ', self::VALID_SCHEMA_VERSIONS),
            ];
        }

        // Parse types and relations
        foreach ($lines as $lineIdx => $line) {
            $lineNumber = $lineIdx + 1; // 1-indexed
            $trimmedLine = trim($line);

            if ('' === $trimmedLine) {
                continue;
            }

            if ('model' === $trimmedLine) {
                continue;
            }

            if (str_starts_with($trimmedLine, 'schema')) {
                continue;
            }

            // Type declaration
            $matches = [];

            if (1 === preg_match('/^type\s+([a-z][a-z0-9_]*)\s*$/', $trimmedLine, $matches) && isset($matches[1])) {
                $currentType = $matches[1];
                $definedTypes[] = $currentType;
                $inRelationsBlock = false;

                continue;
            }

            // Relations block
            if ('relations' === $trimmedLine && null !== $currentType) {
                $inRelationsBlock = true;

                continue;
            }

            // Relation definition
            $matches = [];

            if ($inRelationsBlock && 1 === preg_match('/^define\s+([a-z][a-z0-9_]*)\s*:\s*(.+)$/', $trimmedLine, $matches) && isset($matches[1], $matches[2])) {
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
                'line' => null,
                'type' => 'reference',
                'message' => sprintf("Referenced type '%s' is not defined", $undefinedType),
            ];
        }

        return $errors;
    }

    /**
     * Validate relation definition syntax.
     *
     * @param  string                                                           $definition
     * @param  int                                                              $lineNumber
     * @return array<int, array{line: int|null, type: string, message: string}>
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
        if (0 === preg_match('/^\[.+\]/', $definition) && ! str_contains($definition, ' or ') && ! str_contains($definition, ' and ')) {
            $errors[] = [
                'line' => $lineNumber,
                'type' => 'syntax',
                'message' => 'Invalid relation definition syntax',
            ];
        }

        return $errors;
    }
}
