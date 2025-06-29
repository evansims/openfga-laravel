<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Exception;
use Mockery;
use Mockery\{Mock, MockInterface};
use PHPUnit\Framework\AssertionFailedError;

use function is_string;

/**
 * Mock debugging utilities to make mock failures easier to diagnose.
 */
final class MockDebugging
{
    /**
     * Assert a mock was called with partial argument matching.
     *
     * @param MockInterface $mock
     * @param string        $method
     * @param array         $partialArgs
     * @param string        $context
     */
    public static function assertCalledWithPartial(
        MockInterface $mock,
        string $method,
        array $partialArgs,
        string $context = '',
    ): void {
        $mock->shouldHaveReceived($method)
            ->withArgs(function (...$actualArgs) use ($partialArgs, $context) {
                foreach ($partialArgs as $index => $expectedArg) {
                    if (! isset($actualArgs[$index])) {
                        TestDebugging::failWithDebugInfo(
                            "Missing argument at index {$index}",
                            [
                                'Expected argument' => $expectedArg,
                                'Actual arguments' => $actualArgs,
                                'Context' => $context,
                            ],
                        );
                    }

                    if ($actualArgs[$index] !== $expectedArg) {
                        TestDebugging::failWithDebugInfo(
                            "Argument mismatch at index {$index}",
                            [
                                'Expected' => $expectedArg,
                                'Actual' => $actualArgs[$index],
                                'All arguments' => $actualArgs,
                                'Context' => $context,
                            ],
                        );
                    }
                }

                return true;
            });
    }

    /**
     * Create a mock with enhanced debugging capabilities.
     *
     * @param string $class
     * @param string $description
     */
    public static function createDebugMock(
        string $class,
        string $description = '',
    ): MockInterface {
        $mock = Mockery::mock($class);

        // Add description for debugging
        if ($description) {
            $mock->mockery_getName = "{$class} ({$description})";
        }

        return $mock;
    }

    /**
     * Create a spy mock that logs all calls for debugging.
     *
     * @param string $class
     * @param bool   $logCalls
     */
    public static function createSpy(
        string $class,
        bool $logCalls = true,
    ): MockInterface {
        $spy = Mockery::spy($class);

        if ($logCalls && (getenv('DEBUG_TESTS') || getenv('PEST_VERBOSE'))) {
            // Intercept all method calls for logging
            $spy->shouldReceive('*')->andReturnUsing(function (...$args) use ($class) {
                $method = debug_backtrace()[1]['function'] ?? 'unknown';
                TestDebugging::log("Spy called: {$class}::{$method}", $args);

                return null;
            });
        }

        return $spy;
    }

    /**
     * Create a mock that fails with helpful context.
     *
     * @param string $class
     * @param array  $allowedMethods
     */
    public static function createStrictMock(
        string $class,
        array $allowedMethods = [],
    ): MockInterface {
        $mock = Mockery::mock($class)->makePartial();

        // By default, disallow all methods except those specified
        $mock->shouldReceive('*')->andReturnUsing(function (...$args) use ($class): void {
            $method = debug_backtrace()[1]['function'] ?? 'unknown';

            TestDebugging::failWithDebugInfo(
                'Unexpected method call on strict mock',
                [
                    'Mock class' => $class,
                    'Called method' => $method,
                    'Arguments' => $args,
                    'Hint' => 'Add this method to allowedMethods or set up an expectation',
                ],
            );
        });

        // Allow specified methods
        foreach ($allowedMethods as $method) {
            $mock->shouldReceive($method)->passthru();
        }

        return $mock;
    }

    /**
     * Set up a mock expectation with debugging context.
     *
     * @param MockInterface $mock
     * @param string        $method
     * @param array         $arguments
     * @param mixed|null    $returnValue
     * @param string        $context
     */
    public static function expectCall(
        MockInterface $mock,
        string $method,
        array $arguments = [],
        mixed $returnValue = null,
        string $context = '',
    ): void {
        $expectation = $mock->shouldReceive($method);

        if (! empty($arguments)) {
            $expectation->with(...$arguments);
        }

        if (null !== $returnValue) {
            $expectation->andReturn($returnValue);
        }

        $expectation->once();

        // Store context for debugging
        if ($context) {
            $expectation->getMock()->mockery_getExpectations($method)[0]->_debugContext = $context;
        }
    }

    /**
     * Verify all mock expectations with enhanced error messages.
     *
     * @param array  $mocks
     * @param string $testContext
     */
    public static function verifyMocks(array $mocks, string $testContext = ''): void
    {
        foreach ($mocks as $name => $mock) {
            try {
                if ($mock instanceof Mock) {
                    $mock->mockery_verify();
                }
            } catch (Exception $e) {
                $mockName = is_string($name) ? $name : $mock::class;
                $context = $testContext ? " in context: {$testContext}" : '';

                throw new AssertionFailedError("Mock verification failed for {$mockName}{$context}\n\n" . self::formatMockExpectations($mock) . "\n\n" . $e->getMessage());
            }
        }
    }

    /**
     * Format mock expectations for debugging output.
     *
     * @param Mock $mock
     */
    private static function formatMockExpectations(Mock $mock): string
    {
        $output = "Expected calls:\n";

        $expectations = $mock->mockery_getExpectations();

        foreach ($expectations as $method => $methodExpectations) {
            foreach ($methodExpectations as $expectation) {
                $output .= "  - {$method}(";

                if ($expectation->_expectedArgs) {
                    $args = array_map(
                        fn ($arg) => TestDebugging::formatValue($arg),
                        $expectation->_expectedArgs,
                    );
                    $output .= implode(', ', $args);
                }

                $output .= ')';

                if (isset($expectation->_debugContext)) {
                    $output .= " // {$expectation->_debugContext}";
                }

                $output .= "\n";
            }
        }

        return $output;
    }
}
