<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Architecture;

use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;

// Ensure our architectural decisions are enforced
arch('controllers should not directly use OpenFGA client')
    ->expect('OpenFGA\ClientInterface')
    ->not->toBeUsedIn('App\Http\Controllers');

arch('commands should be final')
    ->expect('OpenFGA\Laravel\Console\Commands')
    ->classes()
    ->toBeFinal();

arch('service providers should extend Laravel service provider')
    ->expect('OpenFGA\Laravel')
    ->classes()
    ->toHaveSuffix('ServiceProvider')
    ->toExtend('Illuminate\Support\ServiceProvider');

arch('facades should extend Laravel facade')
    ->expect('OpenFGA\Laravel\Facades')
    ->toExtend('Illuminate\Support\Facades\Facade');

arch('no debug statements')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'echo'])
    ->not->toBeUsed();

arch('strict types declaration')
    ->expect('OpenFGA\Laravel')
    ->toUseStrictTypes();

arch('interfaces should have proper naming')
    ->expect('OpenFGA\Laravel\Contracts')
    ->toBeInterfaces()
    ->toHaveSuffix('Interface');

arch('traits should have proper naming')
    ->expect('OpenFGA\Laravel\Traits')
    ->toBeTraits();

arch('abstract classes should be abstract')
    ->expect('OpenFGA\Laravel\Abstracts')
    ->toBeAbstract();

arch('events should extend base event')
    ->expect('OpenFGA\Laravel\Events')
    ->classes()
    ->toExtend('OpenFGA\Laravel\Events\AbstractOpenFgaEvent')
    ->ignoring('OpenFGA\Laravel\Events\AbstractOpenFgaEvent');

arch('exceptions should extend base exception')
    ->expect('OpenFGA\Laravel\Exceptions')
    ->toExtend('Exception')
    ->orToExtend('RuntimeException')
    ->orToExtend('InvalidArgumentException');

arch('DTOs should be immutable')
    ->expect('OpenFGA\Laravel\DTOs')
    ->toBeReadonly()
    ->toHaveConstructor();

arch('managers should implement interface')
    ->expect('OpenFGA\Laravel\OpenFgaManager')
    ->toExtend(AbstractOpenFgaManager::class);

arch('no Laravel facades in domain logic')
    ->expect('Illuminate\Support\Facades')
    ->not->toBeUsedIn('OpenFGA\Laravel\Abstracts')
    ->not->toBeUsedIn('OpenFGA\Laravel\DTOs');

arch('middleware should extend Laravel middleware')
    ->expect('OpenFGA\Laravel\Http\Middleware')
    ->toImplement('Closure');

arch('test cases should use correct base class')
    ->expect('OpenFGA\Laravel\Tests')
    ->classes()
    ->toExtend('OpenFGA\Laravel\Tests\TestCase')
    ->orToExtend('OpenFGA\Laravel\Testing\IntegrationTestCase')
    ->orToExtend('Orchestra\Testbench\TestCase')
    ->ignoring([
        'OpenFGA\Laravel\Tests\TestCase',
        'OpenFGA\Laravel\Testing\IntegrationTestCase',
        'OpenFGA\Laravel\Tests\Support\TestStreamWrapper',
        'OpenFGA\Laravel\Tests\Unit\Testing\IntegrationTestCaseTest',
    ]);

arch('no direct config access outside service provider')
    ->expect('config')
    ->not->toBeUsedIn('OpenFGA\Laravel')
    ->ignoring([
        'OpenFGA\Laravel\OpenFgaServiceProvider',
        'OpenFGA\Laravel\Testing\IntegrationTestCase',
        'OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager',
    ]);

arch('dependency injection over facades')
    ->expect('OpenFGA\Laravel')
    ->not->toUse(['App', 'Auth', 'Cache', 'Config', 'DB', 'Event', 'Gate', 'Log', 'Queue', 'Redis', 'Request', 'Response', 'Route', 'Schema', 'Session', 'Storage', 'URL', 'Validator', 'View'])
    ->ignoring([
        'OpenFGA\Laravel\OpenFgaServiceProvider',
        'OpenFGA\Laravel\Facades',
        'OpenFGA\Laravel\Testing',
        'OpenFGA\Laravel\View\BladeServiceProvider',
    ]);

arch('no circular dependencies')
    ->expect('OpenFGA\Laravel')
    ->toHaveNoCycles();

// Layer architecture
arch('console commands should not depend on HTTP layer')
    ->expect('OpenFGA\Laravel\Console\Commands')
    ->not->toUse('OpenFGA\Laravel\Http');

arch('HTTP layer should not depend on console')
    ->expect('OpenFGA\Laravel\Http')
    ->not->toUse('OpenFGA\Laravel\Console');

// Naming conventions
arch('test files should end with Test suffix')
    ->expect('OpenFGA\Laravel\Tests')
    ->files()
    ->toHaveSuffix('Test.php')
    ->ignoring([
        'OpenFGA\Laravel\Tests\TestCase',
        'OpenFGA\Laravel\Tests\Fixtures',
        'OpenFGA\Laravel\Tests\Support\TestStreamWrapper',
    ]);

// Security
arch('no hardcoded credentials')
    ->expect('OpenFGA\Laravel')
    ->not->toContain(['password', 'secret', 'token', 'key'])
    ->ignoring([
        'OpenFGA\Laravel\OpenFgaServiceProvider', // Config keys
        'OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager', // Config keys
    ]);
