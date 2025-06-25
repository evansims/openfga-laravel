<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests;

use Mockery;
use Orchestra\Testbench\Concerns\{WithLaravelMigrations, WithWorkbench};

abstract class FeatureTestCase extends TestCase
{
    use WithLaravelMigrations;

    use WithWorkbench;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
