<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use OpenFGA\Laravel\OpenFgaManager;

class DebugbarOpenFgaManager
{
    public function __construct(
        private OpenFgaManager $manager,
        private OpenFgaCollector $collector
    ) {}

    /**
     * Forward calls to the wrapped manager while collecting metrics
     */
    public function __call(string $method, array $arguments)
    {
        $start = microtime(true);

        try {
            $result = $this->manager->$method(...$arguments);
            $duration = microtime(true) - $start;

            // Collect metrics based on method
            $this->collectMetrics($method, $arguments, $result, $duration);

            return $result;
        } catch (\Exception $e) {
            $duration = microtime(true) - $start;
            $this->collectMetrics($method, $arguments, null, $duration, $e);
            throw $e;
        }
    }

    /**
     * Handle property access
     */
    public function __get(string $name)
    {
        return $this->manager->$name;
    }

    /**
     * Check authorization
     */
    public function check(string $user, string $relation, string $object): bool
    {
        $start = microtime(true);
        $result = $this->manager->check($user, $relation, $object);
        $duration = microtime(true) - $start;

        $this->collector->addCheck($user, $relation, $object, $result, $duration);

        return $result;
    }

    /**
     * Batch check authorization
     */
    public function batchCheck(array $checks): array
    {
        $start = microtime(true);
        $results = $this->manager->batchCheck($checks);
        $duration = microtime(true) - $start;

        $this->collector->addBatchCheck($checks, $results, $duration);

        return $results;
    }

    /**
     * Write authorization data
     */
    public function write(array $writes = [], array $deletes = []): void
    {
        $start = microtime(true);
        $this->manager->write($writes, $deletes);
        $duration = microtime(true) - $start;

        $this->collector->addWrite($writes, $deletes, $duration);
    }

    /**
     * Expand authorization
     */
    public function expand(string $relation, string $object): array
    {
        $start = microtime(true);
        $result = $this->manager->expand($relation, $object);
        $duration = microtime(true) - $start;

        $this->collector->addExpand($relation, $object, $result, $duration);

        return $result;
    }

    /**
     * Get a connection
     */
    public function connection(?string $name = null)
    {
        // Return a wrapped connection that also collects metrics
        $connection = $this->manager->connection($name);
        
        // For now, return the connection directly
        // In a full implementation, we would wrap this as well
        return $connection;
    }

    /**
     * Collect metrics for generic method calls
     */
    protected function collectMetrics(
        string $method, 
        array $arguments, 
        $result, 
        float $duration, 
        ?\Exception $exception = null
    ): void {
        $params = [
            'method' => $method,
            'arguments' => $this->sanitizeArguments($arguments),
            'success' => $exception === null,
        ];

        if ($exception !== null) {
            $params['error'] = $exception->getMessage();
        }

        $this->collector->addQuery($method, $params, $duration);
    }

    /**
     * Sanitize arguments for display
     */
    protected function sanitizeArguments(array $arguments): array
    {
        return array_map(function ($arg) {
            if (is_object($arg)) {
                return get_class($arg);
            }
            if (is_array($arg) && count($arg) > 5) {
                return 'array(' . count($arg) . ' items)';
            }
            return $arg;
        }, $arguments);
    }
}