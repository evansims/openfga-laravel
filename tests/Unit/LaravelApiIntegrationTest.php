<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit;

use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\OpenFgaManager;

describe('Laravel API Integration', function (): void {
    beforeEach(function (): void {
        // Simple test configuration
        $this->config = [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'url' => 'http://localhost:8080',
                    'store_id' => 'test-store',
                    'model_id' => 'test-model',
                    'credentials' => ['method' => 'none'],
                ],
            ],
            'cache' => [
                'enabled' => false,
            ],
            'logging' => [
                'enabled' => false,
            ],
        ];
    });

    it('can be instantiated', function (): void {
        $manager = new OpenFgaManager($this->app, $this->config);

        expect($manager)->toBeInstanceOf(OpenFgaManager::class);
    });

    it('supports method chaining for exception configuration', function (): void {
        $manager = new OpenFgaManager($this->app, $this->config);

        $result = $manager->throwExceptions(true);

        expect($result)->toBe($manager);
    });

    it('has a facade', function (): void {
        expect(OpenFga::getFacadeRoot())->toBeInstanceOf(OpenFgaManager::class);
    });

    it('can get and set default connection', function (): void {
        $manager = new OpenFgaManager($this->app, $this->config);

        expect($manager->getDefaultConnection())->toBe('main');

        $manager->setDefaultConnection('other');

        expect($manager->getDefaultConnection())->toBe('other');
    });

    it('can disconnect from connections', function (): void {
        $manager = new OpenFgaManager($this->app, $this->config);

        // Get connections array (initially empty)
        expect($manager->getConnections())->toBeEmpty();

        // Disconnect should not throw
        $manager->disconnect();
        $manager->disconnectAll();

        expect($manager->getConnections())->toBeEmpty();
    });
});
