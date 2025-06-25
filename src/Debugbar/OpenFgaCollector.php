<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\DataCollectorInterface;
use DebugBar\DataCollector\Renderable;

class OpenFgaCollector extends DataCollector implements DataCollectorInterface, Renderable
{
    protected array $queries = [];
    protected array $checks = [];
    protected array $writes = [];
    protected array $expansions = [];
    protected float $totalTime = 0;

    /**
     * Add a permission check to the collector
     */
    public function addCheck(string $user, string $relation, string $object, bool $result, float $duration): void
    {
        $this->checks[] = [
            'user' => $user,
            'relation' => $relation,
            'object' => $object,
            'result' => $result,
            'duration' => $duration,
            'time' => microtime(true),
        ];
        
        $this->totalTime += $duration;
    }

    /**
     * Add a batch check to the collector
     */
    public function addBatchCheck(array $checks, array $results, float $duration): void
    {
        $this->checks[] = [
            'type' => 'batch',
            'count' => count($checks),
            'results' => array_sum($results),
            'duration' => $duration,
            'time' => microtime(true),
        ];
        
        $this->totalTime += $duration;
    }

    /**
     * Add a write operation to the collector
     */
    public function addWrite(array $writes, array $deletes, float $duration): void
    {
        $this->writes[] = [
            'writes' => count($writes),
            'deletes' => count($deletes),
            'duration' => $duration,
            'time' => microtime(true),
        ];
        
        $this->totalTime += $duration;
    }

    /**
     * Add an expand operation to the collector
     */
    public function addExpand(string $relation, string $object, array $result, float $duration): void
    {
        $this->expansions[] = [
            'relation' => $relation,
            'object' => $object,
            'users' => count($result),
            'duration' => $duration,
            'time' => microtime(true),
        ];
        
        $this->totalTime += $duration;
    }

    /**
     * Add a generic query to the collector
     */
    public function addQuery(string $operation, array $params, float $duration): void
    {
        $this->queries[] = [
            'operation' => $operation,
            'params' => $params,
            'duration' => $duration,
            'time' => microtime(true),
        ];
        
        $this->totalTime += $duration;
    }

    /**
     * {@inheritdoc}
     */
    public function collect(): array
    {
        $data = [
            'nb_queries' => count($this->queries) + count($this->checks) + count($this->writes) + count($this->expansions),
            'nb_checks' => count($this->checks),
            'nb_writes' => count($this->writes),
            'nb_expansions' => count($this->expansions),
            'queries' => $this->queries,
            'checks' => $this->checks,
            'writes' => $this->writes,
            'expansions' => $this->expansions,
            'duration' => $this->totalTime,
            'duration_str' => $this->getDataFormatter()->formatDuration($this->totalTime),
        ];

        // Calculate cache hit rate if applicable
        $cachedChecks = array_filter($this->checks, fn($check) => isset($check['cached']) && $check['cached']);
        if (count($this->checks) > 0) {
            $data['cache_hit_rate'] = round((count($cachedChecks) / count($this->checks)) * 100, 2);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'openfga';
    }

    /**
     * {@inheritdoc}
     */
    public function getWidgets(): array
    {
        return [
            'openfga' => [
                'icon' => 'shield',
                'widget' => 'PhpDebugBar.Widgets.OpenFgaWidget',
                'map' => 'openfga',
                'default' => '[]',
            ],
            'openfga:badge' => [
                'map' => 'openfga.nb_queries',
                'default' => 0,
            ],
        ];
    }

    /**
     * Get widget assets
     */
    public function getAssets(): array
    {
        return [
            'css' => 'widgets/openfga/widget.css',
            'js' => 'widgets/openfga/widget.js',
        ];
    }
}