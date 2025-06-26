<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Debugbar;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenFGA\ClientInterface;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Profiling\OpenFgaProfiler;

use function count;
use function is_array;
use function is_object;

final readonly class DebugbarOpenFgaManager
{
    public function __construct(
        private OpenFgaManager $manager,
        private OpenFgaCollector $collector,
    ) {
    }

    /**
     * Forward calls to the wrapped manager while collecting metrics.
     *
     * @param string            $method
     * @param array<int, mixed> $arguments
     *
     * @throws Exception
     *
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        $start = microtime(true);

        try {
            /** @var mixed $result */
            $result = $this->manager->{$method}(...$arguments);
            $duration = microtime(true) - $start;

            // Collect metrics based on method
            $this->collectMetrics($method, $arguments, $duration);

            return $result;
        } catch (Exception $exception) {
            $duration = microtime(true) - $start;
            $this->collectMetrics($method, $arguments, $duration, $exception);

            throw $exception;
        }
    }

    /**
     * Handle property access.
     *
     * @param  string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->manager->{$name};
    }

    /**
     * Batch check authorization.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $checks
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException
     *
     * @return array<string, bool>
     */
    public function batchCheck(array $checks): array
    {
        $start = microtime(true);
        $results = $this->manager->batchCheck($checks);
        $duration = microtime(true) - $start;

        // Add to profiler instead of collector
        $profiler = app(OpenFgaProfiler::class);
        $profile = $profiler->startProfile('batchCheck', ['checks' => $checks]);
        $profile->end(true)->addMetadata('duration', $duration);

        return $results;
    }

    /**
     * Check authorization.
     *
     * @param string $user
     * @param string $relation
     * @param string $object
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException|\Psr\SimpleCache\InvalidArgumentException
     */
    public function check(string $user, string $relation, string $object): bool
    {
        $start = microtime(true);
        $result = $this->manager->check($user, $relation, $object);
        $duration = microtime(true) - $start;

        // Add to profiler instead of collector
        $profiler = app(OpenFgaProfiler::class);
        $profile = $profiler->startProfile('check', ['user' => $user, 'relation' => $relation, 'object' => $object]);
        $profile->end(true)->addMetadata('duration', $duration);

        return $result;
    }

    /**
     * Get a connection.
     *
     * @param ?string $name
     *
     * @throws InvalidArgumentException
     */
    public function connection(?string $name = null): ClientInterface
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
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public function expand(string $relation, string $object): array
    {
        $start = microtime(true);
        $result = $this->manager->expand($relation, $object);
        $duration = microtime(true) - $start;

        // Add to profiler instead of collector
        $profiler = app(OpenFgaProfiler::class);
        $profile = $profiler->startProfile('expand', ['relation' => $relation, 'object' => $object]);
        $profile->end(true)->addMetadata('duration', $duration);

        return $result;
    }

    /**
     * Write authorization data.
     *
     * @param array<int, array{user: string, relation: string, object: string}> $writes
     * @param array<int, array{user: string, relation: string, object: string}> $deletes
     *
     * @throws BindingResolutionException|ClientThrowable|Exception|InvalidArgumentException
     */
    public function write(array $writes = [], array $deletes = []): void
    {
        $start = microtime(true);
        // The manager's write method expects TupleKeysInterface, so we need to use writeBatch instead
        $this->manager->writeBatch($writes, $deletes);
        $duration = microtime(true) - $start;

        // Add to profiler instead of collector
        $profiler = app(OpenFgaProfiler::class);
        $profile = $profiler->startProfile('write', ['writes' => count($writes), 'deletes' => count($deletes)]);
        $profile->end(true)->addMetadata('duration', $duration);
    }

    /**
     * Collect metrics for generic method calls.
     *
     * @param string            $method
     * @param array<int, mixed> $arguments
     * @param float             $duration
     * @param ?Exception        $exception
     */
    private function collectMetrics(
        string $method,
        array $arguments,
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

        // Add to profiler instead of collector
        $profiler = app(OpenFgaProfiler::class);
        $profile = $profiler->startProfile($method, $params);
        $profile->end(true)->addMetadata('duration', $duration);
    }

    /**
     * Sanitize arguments for display.
     *
     * @param  array<int, mixed> $arguments
     * @return array<int, mixed>
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
