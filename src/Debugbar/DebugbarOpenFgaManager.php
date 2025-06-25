<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use Exception;
use OpenFGA\Laravel\OpenFgaManager;

use function count;
use function is_array;
use function is_object;

final class DebugbarOpenFgaManager
{
    public function __construct(
        private readonly OpenFgaManager $manager,
        private readonly OpenFgaCollector $collector,
    ) {
    }

    /**
     * Forward calls to the wrapped manager while collecting metrics.
     *
     * @param string $method
     * @param array  $arguments
     */
    public function __call(string $method, array $arguments)
    {
        $start = microtime(true);

        try {
            $result = $this->manager->{$method}(...$arguments);
            $duration = microtime(true) - $start;

            // Collect metrics based on method
            $this->collectMetrics($method, $arguments, $result, $duration);

            return $result;
        } catch (Exception $exception) {
            $duration = microtime(true) - $start;
            $this->collectMetrics($method, $arguments, null, $duration, $exception);

            throw $exception;
        }
    }

    /**
     * Handle property access.
     *
     * @param string $name
     */
    public function __get(string $name)
    {
        return $this->manager->{$name};
    }

    /**
     * Batch check authorization.
     *
     * @param array $checks
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
     * Check authorization.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
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
     * Get a connection.
     *
     * @param ?string $name
     */
    public function connection(?string $name = null)
    {
        // Return a wrapped connection that also collects metrics
        return $this->manager->connection($name);
        // For now, return the connection directly
        // In a full implementation, we would wrap this as well
    }

    /**
     * Expand authorization.
     *
     * @param string $relation
     * @param string $object
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
     * Write authorization data.
     *
     * @param array $writes
     * @param array $deletes
     */
    public function write(array $writes = [], array $deletes = []): void
    {
        $start = microtime(true);
        $this->manager->write($writes, $deletes);
        $duration = microtime(true) - $start;

        $this->collector->addWrite($writes, $deletes, $duration);
    }

    /**
     * Collect metrics for generic method calls.
     *
     * @param string     $method
     * @param array      $arguments
     * @param mixed      $result
     * @param float      $duration
     * @param ?Exception $exception
     */
    private function collectMetrics(
        string $method,
        array $arguments,
        $result,
        float $duration,
        ?Exception $exception = null,
    ): void {
        $params = [
            'method' => $method,
            'arguments' => $this->sanitizeArguments($arguments),
            'success' => ! $exception instanceof Exception,
        ];

        if ($exception instanceof Exception) {
            $params['error'] = $exception->getMessage();
        }

        $this->collector->addQuery($method, $params, $duration);
    }

    /**
     * Sanitize arguments for display.
     *
     * @param array $arguments
     */
    private function sanitizeArguments(array $arguments): array
    {
        return array_map(static function ($arg) {
            if (is_object($arg)) {
                return $arg::class;
            }

            if (is_array($arg) && 5 < count($arg)) {
                return 'array(' . count($arg) . ' items)';
            }

            return $arg;
        }, $arguments);
    }
}
