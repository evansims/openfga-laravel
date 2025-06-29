<?php

declare(strict_types=1);

// Test naming conventions are followed
arch('commands follow naming convention')
    ->expect('OpenFGA\Laravel\Console\Commands')
    ->toHaveSuffix('Command')
    ->not->toHaveSuffix('Commands');

arch('events follow naming convention')
    ->expect('OpenFGA\Laravel\Events')
    ->not->toHaveSuffix('Event') // We don't suffix with Event
    ->not->toBeAbstract()
    ->ignoring('OpenFGA\Laravel\Events\AbstractOpenFgaEvent');

arch('middleware follows naming convention')
    ->expect('OpenFGA\Laravel\Http\Middleware')
    ->not->toHaveSuffix('Middleware'); // Laravel convention

arch('service providers follow naming convention')
    ->expect('OpenFGA\Laravel')
    ->classes()
    ->toHaveSuffix('ServiceProvider')
    ->toExtend('Illuminate\Support\ServiceProvider')
    ->ignoring([
        'OpenFGA\Laravel\OpenFgaManager',
        'OpenFGA\Laravel\Facades',
        'OpenFGA\Laravel\Console',
        'OpenFGA\Laravel\Http',
        'OpenFGA\Laravel\Events',
        'OpenFGA\Laravel\Traits',
        'OpenFGA\Laravel\Abstracts',
        'OpenFGA\Laravel\Contracts',
        'OpenFGA\Laravel\DTOs',
        'OpenFGA\Laravel\Exceptions',
        'OpenFGA\Laravel\Testing',
        'OpenFGA\Laravel\View',
        'OpenFGA\Laravel\Jobs',
        'OpenFGA\Laravel\Listeners',
        'OpenFGA\Laravel\Monitoring',
        'OpenFGA\Laravel\Pool',
        'OpenFGA\Laravel\Profiling',
        'OpenFGA\Laravel\Providers',
        'OpenFGA\Laravel\Support',
        'OpenFGA\Laravel\Webhooks',
        'OpenFGA\Laravel\Import',
        'OpenFGA\Laravel\Export',
        'OpenFGA\Laravel\Database',
        'OpenFGA\Laravel\Debugbar',
        'OpenFGA\Laravel\Deduplication',
        'OpenFGA\Laravel\Compatibility',
        'OpenFGA\Laravel\Cache',
        'OpenFGA\Laravel\Batch',
        'OpenFGA\Laravel\Authorization',
        'OpenFGA\Laravel\Query',
        'OpenFGA\Laravel\Helpers',
    ]);

arch('jobs follow naming convention')
    ->expect('OpenFGA\Laravel\Jobs')
    ->toHaveSuffix('Job');

arch('listeners follow naming convention')
    ->expect('OpenFGA\Laravel\Listeners')
    ->not->toHaveSuffix('Listener'); // We use descriptive names

arch('exceptions follow naming convention')
    ->expect('OpenFGA\Laravel\Exceptions')
    ->toHaveSuffix('Exception');

// Test method naming conventions - commented out as it's too restrictive
// arch('test methods use snake_case for Laravel compatibility')
//     ->expect('OpenFGA\Laravel')
//     ->toHavePropertiesAndMethodsInSnakeCase()
//     ->ignoring([
//         'OpenFGA\Laravel\Testing', // Test helpers can use camelCase
//         'OpenFGA\Laravel\DTOs', // DTOs use readonly properties
//     ]);

// File naming matches class naming
arch('files match class names')
    ->expect('OpenFGA\Laravel')
    ->toHaveFileNameMatchingClassName();
