<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architectural Tests
|--------------------------------------------------------------------------
|
| These tests ensure that the codebase follows our architectural rules
| and design principles. They help prevent architectural drift and
| maintain code quality standards.
|
*/

// Ensure all files in src use strict types
arch('strict types declaration')
    ->expect('OpenFGA\Laravel')
    ->toUseStrictTypes();

// Ensure no debugging functions are left in the code
arch('no debugging functions')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

// Ensure contracts/interfaces are properly used
arch('contracts are interfaces')
    ->expect('OpenFGA\Laravel\Contracts')
    ->toBeInterfaces();

// Ensure traits follow naming convention
arch('traits use trait suffix')
    ->expect('OpenFGA\Laravel\Traits')
    ->toBeTraits()
    ->toHaveSuffix('Trait')
    ->ignoring([
        'OpenFGA\Laravel\Traits\HasAuthorization',
        'OpenFGA\Laravel\Traits\ResolvesAuthorizationObject',
        'OpenFGA\Laravel\Traits\ResolvesAuthorizationUser',
        'OpenFGA\Laravel\Traits\ManagerOperations',
        'OpenFGA\Laravel\Traits\SpatieCompatible',
    ]);

// Ensure exceptions extend the base exception (with acceptable alternatives)
arch('exceptions extend base exception')
    ->expect('OpenFGA\Laravel\Exceptions')
    ->toExtend('OpenFGA\Laravel\Exceptions\OpenFgaException')
    ->ignoring([
        'OpenFGA\Laravel\Exceptions\OpenFgaException',
        'OpenFGA\Laravel\Exceptions\ConnectionPoolException', // Extends RuntimeException for performance reasons
    ]);

// Ensure service providers extend Laravel's ServiceProvider
arch('service providers extend laravel provider')
    ->expect('OpenFGA\Laravel\Providers')
    ->classes()
    ->toExtend('Illuminate\Support\ServiceProvider');

// Ensure commands extend Laravel's Command
arch('commands extend laravel command')
    ->expect('OpenFGA\Laravel\Console\Commands')
    ->toExtend('Illuminate\Console\Command');

// Ensure core does not depend on facades (with specific exceptions)
arch('core does not depend on facades')
    ->expect('OpenFGA\Laravel\Abstracts')
    ->not->toUse('Illuminate\Support\Facades')
    ->ignoring([
        'OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager', // Uses Log facade for logging
    ]);

// Ensure proper separation of concerns - controllers don't use models directly
arch('separation of concerns')
    ->expect('OpenFGA\Laravel\Http\Controllers')
    ->not->toUse('Illuminate\Database\Eloquent\Model');

// Ensure dependencies are properly injected
arch('no hardcoded dependencies')
    ->expect('OpenFGA\Laravel')
    ->not->toUse('new')
    ->ignoring([
        'OpenFGA\Laravel\Exceptions',
        'OpenFGA\Laravel\Batch',
        'OpenFGA\Laravel\Cache',
        'OpenFGA\Laravel\Models',
        'OpenFGA\Laravel\View',
        'OpenFGA\Laravel\Events',
        'OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager',
        'OpenFGA\Laravel\Abstracts\AbstractAuthorizationQuery',
        'OpenFGA\Laravel\Testing',
        'OpenFGA\Laravel\Query',
        'OpenFGA\Laravel\Webhooks',
        'OpenFGA\Laravel\Profiling',
        'OpenFGA\Laravel\Console\Commands',
        'OpenFGA\Laravel\Helpers',
        'OpenFGA\Laravel\OpenFgaManager',
        'OpenFGA\Laravel\Authorization',
    ]);

// Ensure test helpers are only used in tests (with exceptions for setup/benchmark commands)
arch('test helpers only in tests')
    ->expect('OpenFGA\Laravel\Testing')
    ->toOnlyBeUsedIn([
        'Tests',
        'OpenFGA\Laravel\Testing', // Allow within Testing namespace itself
        'OpenFGA\Laravel\Console\Commands\SetupIntegrationTestsCommand', // Setup command needs testing utilities
        'OpenFGA\Laravel\Console\Commands\BenchmarkCommand', // Benchmark command uses performance testing utilities
        'OpenFGA\Laravel\Console\Commands\SnapshotCommand', // Snapshot command uses permission snapshot utilities
    ]);

// Ensure proper error handling
arch('proper error handling')
    ->expect('OpenFGA\Laravel')
    ->not->toUse('die')
    ->not->toUse('exit')
    ->not->toUse('eval');

// Ensure all classes use declare(strict_types=1)
arch('all files use strict types')
    ->expect('OpenFGA\Laravel')
    ->toUseStrictTypes();