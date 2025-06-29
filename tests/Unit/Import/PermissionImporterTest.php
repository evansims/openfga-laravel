<?php

declare(strict_types=1);

use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\Import\PermissionImporter;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Models\Collections\TupleKeys;

uses(TestCase::class);

describe('PermissionImporter', function (): void {
    beforeEach(function (): void {
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $this->importer = new PermissionImporter($this->mockManager);
    });

    describe('format detection', function (): void {
        it('detects format from extension', function (): void {
            $data = ['permissions' => [['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1']]];

            // Test JSON detection
            $jsonFile = sys_get_temp_dir() . '/test.json';
            file_put_contents($jsonFile, json_encode($data));

            $this->mockManager->shouldReceive('write')->once();
            $this->importer->importFromFile($jsonFile);
            unlink($jsonFile);

            // Test CSV detection
            $csvFile = sys_get_temp_dir() . '/test.csv';
            file_put_contents($csvFile, "user,relation,object\nuser:1,viewer,document:1");

            $this->mockManager->shouldReceive('write')->once();
            $this->importer->importFromFile($csvFile);
            unlink($csvFile);
        });

        it('handles dry run mode', function (): void {
            $data = [
                ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
            ];

            $this->mockManager->shouldNotReceive('write');

            $stats = $this->importer->importFromArray($data, ['dry_run' => true]);

            expect($stats)->toBe([
                'processed' => 1,
                'imported' => 1,
                'skipped' => 0,
                'errors' => 0,
            ]);
        });
    });

    describe('file handling', function (): void {
        it('handles empty json structure', function (): void {
            $filename = sys_get_temp_dir() . '/empty.json';
            file_put_contents($filename, '{"permissions": []}');

            $this->mockManager->shouldNotReceive('write');

            $stats = $this->importer->importFromFile($filename);

            expect($stats)->toBe([
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'errors' => 0,
            ]);

            unlink($filename);
        });

        it('handles invalid csv headers', function (): void {
            $csvContent = "invalid,headers\nuser:1,viewer";
            $filename = sys_get_temp_dir() . '/invalid_headers.csv';
            file_put_contents($filename, $csvContent);

            expect(fn () => $this->importer->importFromFile($filename))
                ->toThrow(RuntimeException::class, 'CSV must have headers: user, relation, object');

            unlink($filename);
        });

        it('handles invalid json', function (): void {
            $filename = sys_get_temp_dir() . '/invalid.json';
            file_put_contents($filename, '{invalid json}');

            expect(fn () => $this->importer->importFromFile($filename))
                ->toThrow(RuntimeException::class, 'Invalid JSON');

            unlink($filename);
        });

        it('handles malformed csv rows', function (): void {
            $csvContent = "user,relation,object\nuser:1,viewer,document:1\ninvalid,row\nuser:2,editor,document:2";
            $filename = sys_get_temp_dir() . '/malformed.csv';
            file_put_contents($filename, $csvContent);

            $this->mockManager->shouldReceive('write')
                ->once()
                ->with(Mockery::on(function (TupleKeys $tuples) {
                    return 2 === $tuples->count(); // Only valid rows should be processed
                }));

            $stats = $this->importer->importFromFile($filename);

            expect($stats)->toBe([
                'processed' => 2,
                'imported' => 2,
                'skipped' => 0,
                'errors' => 0,
            ]);

            unlink($filename);
        });

        it('handles missing file', function (): void {
            expect(fn () => $this->importer->importFromFile('nonexistent.json'))
                ->toThrow(RuntimeException::class, 'Import file not found');
        });
    });

    describe('array import', function (): void {
        it('imports from array with default options', function (): void {
            $data = [
                ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:1'],
            ];

            $this->mockManager->shouldReceive('write')
                ->once()
                ->with(Mockery::type(TupleKeys::class));

            $stats = $this->importer->importFromArray($data);

            expect($stats)->toBe([
                'processed' => 2,
                'imported' => 2,
                'skipped' => 0,
                'errors' => 0,
            ]);
        });

        it('imports from array with permissions wrapper', function (): void {
            $data = [
                'permissions' => [
                    ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
                    ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:1'],
                ],
            ];

            $this->mockManager->shouldReceive('write')
                ->once()
                ->with(Mockery::type(TupleKeys::class));

            $stats = $this->importer->importFromArray($data);

            expect($stats)->toBe([
                'processed' => 2,
                'imported' => 2,
                'skipped' => 0,
                'errors' => 0,
            ]);
        });
    });

    describe('file imports', function (): void {
        it('imports from csv file', function (): void {
            $csvContent = "user,relation,object\nuser:1,viewer,document:1\nuser:2,editor,document:1";
            $filename = sys_get_temp_dir() . '/test_import.csv';
            file_put_contents($filename, $csvContent);

            $this->mockManager->shouldReceive('write')
                ->once()
                ->with(Mockery::type(TupleKeys::class));

            $stats = $this->importer->importFromFile($filename);

            expect($stats)->toBe([
                'processed' => 2,
                'imported' => 2,
                'skipped' => 0,
                'errors' => 0,
            ]);

            unlink($filename);
        });

        it('imports from json file', function (): void {
            $data = [
                'permissions' => [
                    ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
                ],
            ];

            $filename = sys_get_temp_dir() . '/test_import.json';
            file_put_contents($filename, json_encode($data));

            $this->mockManager->shouldReceive('write')
                ->once()
                ->with(Mockery::type(TupleKeys::class));

            $stats = $this->importer->importFromFile($filename);

            expect($stats)->toBe([
                'processed' => 1,
                'imported' => 1,
                'skipped' => 0,
                'errors' => 0,
            ]);

            unlink($filename);
        });

        it('imports from yaml file', function (): void {
            if (function_exists('yaml_parse_file') && function_exists('yaml_emit')) {
                // YAML extension is available, test normal operation
                $data = [
                    'permissions' => [
                        ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
                    ],
                ];

                $filename = sys_get_temp_dir() . '/test_import.yaml';
                file_put_contents($filename, yaml_emit($data));

                $this->mockManager->shouldReceive('write')
                    ->once()
                    ->with(Mockery::type(TupleKeys::class));

                $stats = $this->importer->importFromFile($filename);

                expect($stats)->toBe([
                    'processed' => 1,
                    'imported' => 1,
                    'skipped' => 0,
                    'errors' => 0,
                ]);

                unlink($filename);
            } else {
                // YAML extension not available, test error case
                $filename = sys_get_temp_dir() . '/test_import.yaml';
                file_put_contents($filename, 'test: data');

                expect(fn () => $this->importer->importFromFile($filename))
                    ->toThrow(RuntimeException::class, 'YAML extension not installed');

                unlink($filename);
            }
        });

        it('processes direct permission arrays in json', function (): void {
            $data = [
                ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'],
                ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:1'],
            ];

            $filename = sys_get_temp_dir() . '/direct_array.json';
            file_put_contents($filename, json_encode($data));

            $this->mockManager->shouldReceive('write')
                ->once()
                ->with(Mockery::type(TupleKeys::class));

            $stats = $this->importer->importFromFile($filename);

            expect($stats)->toBe([
                'processed' => 2,
                'imported' => 2,
                'skipped' => 0,
                'errors' => 0,
            ]);

            unlink($filename);
        });
    });

    describe('batch processing', function (): void {
        it('processes in batches', function (): void {
            $data = array_fill(0, 250, ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1']);

            $this->mockManager->shouldReceive('write')
                ->times(3) // 250 items with batch_size 100 = 3 batches
                ->with(Mockery::type(TupleKeys::class));

            $stats = $this->importer->importFromArray($data, ['batch_size' => 100]);

            expect($stats)->toBe([
                'processed' => 250,
                'imported' => 250,
                'skipped' => 0,
                'errors' => 0,
            ]);
        });
    });

    describe('statistics', function (): void {
        it('returns statistics', function (): void {
            $stats = $this->importer->getStats();

            expect($stats)->toBe([
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'errors' => 0,
            ]);
        });
    });

    describe('validation', function (): void {
        it('skips validation when disabled', function (): void {
            $data = [
                ['user' => 'invalid_user', 'relation' => 'viewer', 'object' => 'document:1'],
            ];

            $this->mockManager->shouldNotReceive('write');

            $stats = $this->importer->importFromArray($data, ['validate' => false]);

            expect($stats['processed'])->toBe(1);
        });

        it('throws exception for unsupported format', function (): void {
            $filename = sys_get_temp_dir() . '/test.txt';
            file_put_contents($filename, 'test');

            expect(fn () => $this->importer->importFromFile($filename))
                ->toThrow(RuntimeException::class, 'Cannot detect format from extension: txt');

            unlink($filename);
        });

        it('validates data structure', function (): void {
            $invalidData = ['not' => 'a permission structure'];

            expect(fn () => $this->importer->importFromArray($invalidData))
                ->toThrow(RuntimeException::class);
        });

        it('validates permission format', function (): void {
            $data = [
                ['user' => 'invalid_user', 'relation' => 'viewer', 'object' => 'document:1'], // Invalid user format
                ['user' => 'user:1', 'relation' => '', 'object' => 'document:1'], // Empty relation
                ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'invalid_object'], // Invalid object format
                ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1'], // Valid permission
            ];

            $this->mockManager->shouldReceive('write')
                ->once()
                ->with(Mockery::on(fn (TupleKeys $tuples) => 1 === $tuples->count()));

            $stats = $this->importer->importFromArray($data);

            expect($stats)->toBe([
                'processed' => 4,
                'imported' => 1,
                'skipped' => 3,
                'errors' => 0,
            ]);
        });

        it('validates yaml extension availability', function (): void {
            $filename = sys_get_temp_dir() . '/test.yaml';
            file_put_contents($filename, 'test: data');

            if (function_exists('yaml_parse_file')) {
                // YAML extension is available, should work (though may fail due to invalid format)
                // We expect it to either succeed or fail with data validation, not extension error
                $result = null;

                try {
                    $result = $this->importer->importFromFile($filename);
                    expect($result)->toBeArray();
                } catch (RuntimeException $e) {
                    // Should not be a YAML extension error
                    expect($e->getMessage())->not->toContain('YAML extension not installed');
                }
            } else {
                // YAML extension not available, should throw specific error
                expect(fn () => $this->importer->importFromFile($filename))
                    ->toThrow(RuntimeException::class, 'YAML extension not installed');
            }

            unlink($filename);
        });
    });
});
