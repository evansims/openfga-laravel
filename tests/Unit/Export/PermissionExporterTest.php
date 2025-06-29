<?php

declare(strict_types=1);

use OpenFGA\Laravel\Export\PermissionExporter;
use OpenFGA\Laravel\Tests\Support\ConfigRestoration;
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);
uses(ConfigRestoration::class);

describe('PermissionExporter', function (): void {
    beforeEach(function (): void {
        $this->setUpConfigRestoration();
        $this->exporter = new PermissionExporter;
    });

    afterEach(function (): void {
        $this->tearDownConfigRestoration();
    });

    describe('export basics', function (): void {
        it('counts exported permissions correctly', function (): void {
            $result = $this->exporter->exportToArray();

            expect($result['metadata']['total'])->toBe(count($result['permissions']));
        });
    });

    describe('format detection', function (): void {
        it('detects format from csv extension', function (): void {
            $filename = 'test.csv';
            $count = $this->exporter->exportToFile($filename);

            expect($count)->toBeGreaterThan(0);
            unlink($filename);
        });

        it('detects format from json extension', function (): void {
            $filename = 'test.json';
            $count = $this->exporter->exportToFile($filename);

            expect($count)->toBeGreaterThan(0);
            unlink($filename);
        });

        it('detects format from yaml extension', function (): void {
            // Test format detection regardless of YAML extension availability
            $filename = 'test.yml';

            if (function_exists('yaml_emit')) {
                // YAML extension is available, test normal operation
                $count = $this->exporter->exportToFile($filename);
                expect($count)->toBeGreaterThan(0);
                unlink($filename);
            } else {
                // YAML extension not available, expect error
                expect(fn () => $this->exporter->exportToFile($filename))
                    ->toThrow(RuntimeException::class, 'YAML extension not installed');
            }
        });
    });

    describe('CSV export', function (): void {
        it('exports csv with proper headers', function (): void {
            $filename = sys_get_temp_dir() . '/test_headers.csv';

            $this->exporter->exportToFile($filename);

            $handle = fopen($filename, 'r');
            $headers = fgetcsv($handle);
            fclose($handle);

            expect($headers)->toBe(['user', 'relation', 'object']);

            unlink($filename);
        });

        it('exports to csv file', function (): void {
            $filename = sys_get_temp_dir() . '/test_export.csv';

            $count = $this->exporter->exportToFile($filename);

            expect($count)->toBeGreaterThan(0)
                ->and(file_exists($filename))->toBeTrue();

            $content = file_get_contents($filename);
            $lines = explode("\n", trim($content));

            expect($lines[0])->toBe('user,relation,object');

            unlink($filename);
        });
    });

    describe('JSON export', function (): void {
        it('exports json with pretty print', function (): void {
            $filename = sys_get_temp_dir() . '/test_pretty.json';

            $this->exporter->exportToFile($filename, [], ['pretty_print' => true]);

            $content = file_get_contents($filename);

            // Pretty printed JSON should have newlines and indentation
            expect($content)->toContain("\n")
                ->and($content)->toContain('  ');

            unlink($filename);
        });

        it('exports json without pretty print', function (): void {
            $filename = sys_get_temp_dir() . '/test_compact.json';

            $this->exporter->exportToFile($filename, [], ['pretty_print' => false]);

            $content = file_get_contents($filename);
            $data = json_decode(json: $content, associative: true);

            // Should be valid JSON but more compact
            expect($data)->toBeArray();

            unlink($filename);
        });

        it('exports to json file', function (): void {
            $filename = sys_get_temp_dir() . '/test_export.json';

            $count = $this->exporter->exportToFile($filename);

            expect($count)->toBeGreaterThan(0)
                ->and(file_exists($filename))->toBeTrue();

            $content = file_get_contents($filename);
            $data = json_decode(json: $content, associative: true);

            expect($data)->toHaveKeys(['metadata', 'permissions']);

            unlink($filename);
        });
    });

    describe('array export', function (): void {
        it('exports to array with metadata', function (): void {
            $result = $this->exporter->exportToArray();

            expect($result)->toHaveKeys(['metadata', 'permissions'])
                ->and($result['metadata'])->toHaveKeys(['exported_at', 'total', 'filters', 'application', 'environment'])
                ->and($result['permissions'])->toBeArray();
        });

        it('exports to array without metadata', function (): void {
            $result = $this->exporter->exportToArray();

            // Manually set options to exclude metadata
            $reflection = new ReflectionClass($this->exporter);
            $optionsProperty = $reflection->getProperty('options');
            $optionsProperty->setAccessible(true);
            $optionsProperty->setValue(objectOrValue: $this->exporter, value: [
                'format' => 'json',
                'include_metadata' => false,
                'pretty_print' => true,
                'chunk_size' => null,
            ]);

            $result = $this->exporter->exportToArray();

            expect($result)->toBeArray()
                ->and($result)->not->toHaveKey('metadata');
        });
    });

    describe('YAML export', function (): void {
        it('exports to yaml file', function (): void {
            $filename = sys_get_temp_dir() . '/test_export.yaml';

            if (function_exists('yaml_emit')) {
                // YAML extension is available, test normal operation
                $count = $this->exporter->exportToFile($filename);

                expect($count)->toBeGreaterThan(0)
                    ->and(file_exists($filename))->toBeTrue();

                $content = file_get_contents($filename);
                expect($content)->toContain('permissions:');

                unlink($filename);
            } else {
                // YAML extension not available, expect error
                expect(fn () => $this->exporter->exportToFile($filename))
                    ->toThrow(RuntimeException::class, 'YAML extension not installed');
            }
        });
    });

    describe('custom options', function (): void {
        it('exports with custom options', function (): void {
            $filename = sys_get_temp_dir() . '/test_custom.json';
            $options = [
                'include_metadata' => false,
                'pretty_print' => false,
            ];

            $count = $this->exporter->exportToFile($filename, [], $options);

            expect($count)->toBeGreaterThan(0);

            $content = file_get_contents($filename);
            $data = json_decode(json: $content, associative: true);

            // Without metadata, should be direct array of permissions
            expect($data)->toBeArray()
                ->and($data)->not->toHaveKey('metadata');

            unlink($filename);
        });
    });

    describe('filters', function (): void {
        it('exports with object filter', function (): void {
            $filters = ['object' => 'document:456'];
            $result = $this->exporter->exportToArray($filters);

            expect($result['metadata']['filters'])->toBe($filters)
                ->and($result['permissions'])->toBeArray();
        });

        it('exports with object type filter', function (): void {
            $filters = ['object_type' => 'document'];
            $result = $this->exporter->exportToArray($filters);

            expect($result['metadata']['filters'])->toBe($filters)
                ->and($result['permissions'])->toBeArray();
        });

        it('exports with relation filter', function (): void {
            $filters = ['relation' => 'viewer'];
            $result = $this->exporter->exportToArray($filters);

            expect($result['metadata']['filters'])->toBe($filters)
                ->and($result['permissions'])->toBeArray();
        });

        it('exports with user filter', function (): void {
            $filters = ['user' => 'user:123'];
            $result = $this->exporter->exportToArray($filters);

            expect($result['metadata']['filters'])->toBe($filters)
                ->and($result['permissions'])->toBeArray();
        });
    });

    describe('metadata', function (): void {
        it('includes application metadata', function (): void {
            config(['app.name' => 'Test App']);

            $result = $this->exporter->exportToArray();

            expect($result['metadata']['application'])->toBe('Test App');
        });

        it('includes environment metadata', function (): void {
            $result = $this->exporter->exportToArray();

            expect($result['metadata']['environment'])->toBe(app()->environment());
        });
    });

    describe('error handling', function (): void {
        it('throws exception for unsupported export format', function (): void {
            expect(fn () => $this->exporter->exportToFile('test.json', [], ['format' => 'xml']))
                ->toThrow(RuntimeException::class, 'Unsupported format: xml');
        });

        it('throws exception for unsupported format', function (): void {
            expect(fn () => $this->exporter->exportToFile('test.txt'))
                ->toThrow(RuntimeException::class, 'Cannot detect format from extension: txt');
        });

        it('validates yaml extension availability', function (): void {
            $filename = 'test.yaml';

            if (function_exists('yaml_emit')) {
                // YAML extension is available, should work normally
                $count = $this->exporter->exportToFile($filename);
                expect($count)->toBeGreaterThan(0);
                unlink($filename);
            } else {
                // YAML extension not available, should throw error
                expect(fn () => $this->exporter->exportToFile($filename))
                    ->toThrow(RuntimeException::class, 'YAML extension not installed');
            }
        });
    });
});
