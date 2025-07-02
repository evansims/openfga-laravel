<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Console\Commands;

use Exception;
use Mockery;
use OpenFGA\Laravel\Console\Commands\RevokeCommand;
use OpenFGA\Laravel\Contracts\ManagerInterface;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\Support\TestStreamWrapper;
use OpenFGA\Laravel\Tests\TestCase;
use RuntimeException;

use function in_array;

uses(TestCase::class);

describe('RevokeCommand', function (): void {
    beforeEach(function (): void {
        // Mock the ManagerInterface instead of the concrete class
        $this->mockManager = Mockery::mock(ManagerInterface::class);
        $this->app->instance(OpenFgaManager::class, $this->mockManager);
        $this->app->instance(ManagerInterface::class, $this->mockManager);
    });

    afterEach(function (): void {
        Mockery::close();

        // Clean up stream wrapper if registered
        if (in_array(needle: 'test', haystack: stream_get_wrappers(), strict: true)) {
            stream_wrapper_unregister('test');
        }
        TestStreamWrapper::reset();
    });

    it('handles errors gracefully', function (): void {
        $this->mockManager->shouldReceive('revoke')
            ->once()
            ->andThrow(new Exception('Connection failed'));

        $this->artisan('openfga:revoke', [
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
        ])
            ->expectsOutput('Error: Connection failed')
            ->assertFailed();
    });

    it('handles errors with json output', function (): void {
        $this->mockManager->shouldReceive('revoke')
            ->once()
            ->andThrow(new Exception('Connection failed'));

        $this->artisan('openfga:revoke', [
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
            '--json' => true,
        ])
            ->expectsOutputToContain('"error": true')
            ->assertFailed();
    });

    it('has correct signature', function (): void {
        $command = new RevokeCommand;
        $command->setLaravel($this->app);

        expect($command->getName())->toBe('openfga:revoke');
        expect($command->getDescription())->toContain('Revoke a permission from a user');

        $definition = $command->getDefinition();

        // Check arguments
        expect($definition->hasArgument('user'))->toBeTrue();
        expect($definition->hasArgument('relation'))->toBeTrue();
        expect($definition->hasArgument('object'))->toBeTrue();

        // Check options
        expect($definition->hasOption('connection'))->toBeTrue();
        expect($definition->hasOption('json'))->toBeTrue();
        expect($definition->hasOption('batch'))->toBeTrue();
    });

    it('outputs json format', function (): void {
        $this->mockManager->shouldReceive('revoke')
            ->once()
            ->with('user:123', 'viewer', 'document:456', null)
            ->andReturn(true);

        $this->artisan('openfga:revoke', [
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
            '--json' => true,
        ])
            ->expectsOutputToContain('"success": true')
            ->assertSuccessful();
    });

    it('revokes permission with connection', function (): void {
        $this->mockManager->shouldReceive('revoke')
            ->once()
            ->with('user:123', 'viewer', 'document:456', 'secondary')
            ->andReturn(true);

        $this->artisan('openfga:revoke', [
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
            '--connection' => 'secondary',
        ])
            ->expectsOutput('✅ Permission revoked successfully')
            ->assertSuccessful();
    });

    it('revokes single permission', function (): void {
        $this->mockManager->shouldReceive('revoke')
            ->once()
            ->with('user:123', 'viewer', 'document:456', null)
            ->andReturn(true);

        $this->artisan('openfga:revoke', [
            'user' => 'user:123',
            'relation' => 'viewer',
            'object' => 'document:456',
        ])
            ->expectsOutput('✅ Permission revoked successfully')
            ->expectsTable(
                ['Field', 'Value'],
                [
                    ['User', 'user:123'],
                    ['Relation', 'viewer'],
                    ['Object', 'document:456'],
                ],
            )
            ->assertSuccessful();
    });

    it('validates arguments', function (): void {
        // Test that the command fails when required arguments are missing
        try {
            $this->artisan('openfga:revoke');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $runtimeException) {
            expect($runtimeException->getMessage())->toContain('Not enough arguments');
        }
    });
});
