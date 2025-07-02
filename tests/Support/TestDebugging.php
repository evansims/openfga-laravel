<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Closure;
use Exception;
use Mockery\Mock;
use Pest\Expectation;
use PHPUnit\Framework\AssertionFailedError;

use function is_array;
use function is_bool;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Test debugging utilities to make test failures easier to diagnose.
 *
 * These helpers provide better error messages, context, and debugging
 * information when tests fail.
 */
final class TestDebugging
{
    /**
     * Assert array structure with detailed diff on failure.
     *
     * @param array  $actual
     * @param array  $expected
     * @param string $message
     */
    public static function assertArrayStructure(
        array $actual,
        array $expected,
        string $message = '',
    ): void {
        $missing = array_diff_key($expected, $actual);
        $extra = array_diff_key($actual, $expected);
        $different = [];

        foreach ($expected as $key => $value) {
            if (isset($actual[$key]) && $actual[$key] !== $value) {
                $different[$key] = [
                    'expected' => $value,
                    'actual' => $actual[$key],
                ];
            }
        }

        if ($missing || $extra || $different) {
            $context = [];

            if ([] !== $missing) {
                $context['Missing keys'] = array_keys($missing);
            }

            if ([] !== $extra) {
                $context['Extra keys'] = array_keys($extra);
            }

            if ([] !== $different) {
                $context['Different values'] = $different;
            }

            $context['Expected structure'] = $expected;
            $context['Actual structure'] = $actual;

            self::assertWithContext(
                false,
                $message ?: 'Array structure mismatch',
                $context,
            );
        }
    }

    /**
     * Create a time-based assertion with performance context.
     *
     * @param Closure $operation
     * @param float   $maxSeconds
     * @param string  $operationName
     */
    public static function assertExecutionTime(
        Closure $operation,
        float $maxSeconds,
        string $operationName = 'Operation',
    ): mixed {
        $start = microtime(true);
        $result = $operation();
        $duration = microtime(true) - $start;

        if ($duration > $maxSeconds) {
            self::assertWithContext(
                false,
                $operationName . ' took too long',
                [
                    'Maximum allowed' => $maxSeconds . 's',
                    'Actual duration' => sprintf('%.3fs', $duration),
                    'Exceeded by' => sprintf('%.3fs', $duration - $maxSeconds),
                ],
            );
        }

        self::log($operationName . ' execution time', sprintf('%.3fs', $duration));

        return $result;
    }

    /**
     * Assert mock was called with debugging information.
     *
     * @param Mock   $mock
     * @param string $method
     * @param array  $expectedArgs
     * @param string $context
     */
    public static function assertMockCalled(
        Mock $mock,
        string $method,
        array $expectedArgs = [],
        string $context = '',
    ): void {
        try {
            $mock->shouldHaveReceived($method)
                ->with(...$expectedArgs)
                ->once();
        } catch (Exception $exception) {
            $debugInfo = [
                'Expected method' => $method,
                'Expected arguments' => $expectedArgs,
                'Context' => $context ?: 'No context provided',
                'Mock' => $mock::class,
            ];

            throw new AssertionFailedError('Mock expectation failed' . self::captureContext($debugInfo) . "\n" . $exception->getMessage());
        }
    }

    /**
     * Assert with detailed context on failure.
     *
     * @param bool   $condition
     * @param string $message
     * @param array  $context
     */
    public static function assertWithContext(
        bool $condition,
        string $message,
        array $context = [],
    ): void {
        if (! $condition) {
            $fullMessage = $message;

            if ([] !== $context) {
                $fullMessage .= self::captureContext($context);
            }

            throw new AssertionFailedError($fullMessage);
        }
    }

    /**
     * Capture and format test context for better error messages.
     *
     * @param array $context
     */
    public static function captureContext(array $context): string
    {
        $output = "\n=== Test Context ===\n";

        foreach ($context as $key => $value) {
            $output .= sprintf(
                "%s: %s\n",
                $key,
                self::formatValue($value),
            );
        }

        return $output;
    }

    /**
     * Create a checkpoint to track test flow.
     *
     * @param string $name
     */
    public static function checkpoint(string $name): void
    {
        self::log('Checkpoint reached: ' . $name);
    }

    /**
     * Create a debug expectation that logs values on failure.
     *
     * @param mixed  $value
     * @param string $description
     */
    public static function debugExpect(mixed $value, string $description = ''): Expectation
    {
        $expectation = expect($value);

        // Store description for use in custom matchers
        if ('' !== $description && '0' !== $description) {
            $expectation->description = $description;
        }

        return $expectation;
    }

    /**
     * Dump test data in a formatted way (only in debug mode).
     *
     * @param mixed  $data
     * @param string $label
     */
    public static function dump(mixed $data, string $label = 'Debug'): void
    {
        if (getenv('DEBUG_TESTS')) {
            echo "\n=== {$label} ===\n";
            echo self::formatValue($data);
            echo "\n================\n";
        }
    }

    /**
     * Create a failing test with helpful debugging information.
     *
     * @param string $message
     * @param array  $debugInfo
     */
    public static function failWithDebugInfo(
        string $message,
        array $debugInfo = [],
    ): never {
        $fullMessage = $message;

        if ([] !== $debugInfo) {
            $fullMessage .= "\n\nDebug Information:";
            $fullMessage .= self::captureContext($debugInfo);
        }

        $fullMessage .= "\n\nTo see more details, run tests with DEBUG_TESTS=1";

        throw new AssertionFailedError($fullMessage);
    }

    /**
     * Format a value for debugging output.
     *
     * @param mixed $value
     */
    public static function formatValue(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => sprintf("'%s'", $value),
            is_array($value) => json_encode($value, JSON_PRETTY_PRINT),
            is_object($value) => self::formatObject($value),
            default => (string) $value,
        };
    }

    /**
     * Log test state for debugging (only in verbose mode).
     *
     * @param string     $message
     * @param mixed|null $data
     */
    public static function log(string $message, mixed $data = null): void
    {
        if (getenv('PEST_VERBOSE') || getenv('DEBUG_TESTS')) {
            echo '
[DEBUG] ' . $message;

            if (null !== $data) {
                echo ': ' . self::formatValue($data);
            }

            echo "\n";
        }
    }

    /**
     * Create a test scenario with clear documentation.
     *
     * @param string  $description
     * @param Closure $test
     */
    public static function scenario(string $description, Closure $test): void
    {
        try {
            $test();
        } catch (Exception $exception) {
            throw new AssertionFailedError("Scenario failed: {$description}\n\n" . $exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Format an object for debugging output.
     *
     * @param object $object
     */
    private static function formatObject(object $object): string
    {
        if ($object instanceof Mock) {
            return sprintf('Mock<%s>', $object::class);
        }

        if (method_exists($object, '__toString')) {
            return (string) $object;
        }

        return sprintf('Object<%s>', $object::class);
    }
}
