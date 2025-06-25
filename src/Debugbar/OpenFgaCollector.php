<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use DebugBar\DataCollector\{DataCollector, DataCollectorInterface, Renderable};
use OpenFGA\Laravel\Profiling\OpenFgaProfiler;

final class OpenFgaCollector extends DataCollector implements DataCollectorInterface, Renderable
{
    public function __construct(
        protected OpenFgaProfiler $profiler,
    ) {
    }

    public function collect(): array
    {
        $summary = $this->profiler->getSummary();
        $profiles = $this->profiler->getProfiles();

        return [
            'nb_operations' => $summary['total_operations'],
            'total_time' => $summary['total_time'],
            'slow_queries' => $summary['slow_queries'],
            'operations' => $profiles->map->toArray()->values()->all(),
            'summary' => $summary,
        ];
    }

    public function getName(): string
    {
        return 'openfga';
    }

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
