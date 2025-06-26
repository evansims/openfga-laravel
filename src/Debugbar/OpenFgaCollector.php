<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use OpenFGA\Laravel\Profiling\OpenFgaProfiler;

// Only define the class if the parent classes exist
if (! class_exists('DebugBar\\DataCollector\\DataCollector')) {
    return;
}

use DebugBar\DataCollector\{DataCollector, DataCollectorInterface, Renderable};

final class OpenFgaCollector extends DataCollector implements DataCollectorInterface, Renderable
{
    public function __construct(
        protected OpenFgaProfiler $profiler,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $summary = $this->profiler->getSummary();
        $profiles = $this->profiler->getProfiles();

        return [
            'nb_operations' => $summary['total_operations'],
            'total_time' => $summary['total_time'],
            'slow_queries' => $summary['slow_queries'],
            'operations' => $profiles->map(static fn ($profile): array => $profile->toArray())->values()->all(),
            'summary' => $summary,
        ];
    }

    public function getName(): string
    {
        return 'openfga';
    }

    /**
     * @return array<string, array<string, mixed>|string>
     */
    public function getWidgets(): array
    {
        return [
            'openfga' => [
                'icon' => 'lock',
                'widget' => 'PhpDebugBar.Widgets.HtmlVariableListWidget',
                'map' => 'openfga',
                'default' => '{}',
            ],
            'openfga:badge' => [
                'map' => 'openfga.nb_operations',
                'default' => 0,
            ],
            'openfga:time' => [
                'map' => 'openfga.total_time',
                'default' => 0,
                'suffix' => 'ms',
            ],
        ];
    }
}
