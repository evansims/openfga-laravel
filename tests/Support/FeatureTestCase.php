<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Mockery;
use OpenFGA\Laravel\Tests\TestCase;
use Orchestra\Testbench\Concerns\{WithLaravelMigrations, WithWorkbench};
use Override;

abstract class FeatureTestCase extends TestCase
{
    use WithLaravelMigrations;

    use WithWorkbench;

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
