<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

use function func_get_args;

/**
 * Extended test double that includes batchCheck support.
 */
final class ExtendedTestOpenFgaManager extends TestOpenFgaManager
{
    public $batchCheckCalls = [];

    protected $batchCheckResults = [];

    public function assertBatchCheckCalled(array $expectedChecks): void
    {
        foreach ($this->batchCheckCalls as $call) {
            if ($call[0] === $expectedChecks) {
                return;
            }
        }

        throw new AssertionFailedError(message: 'Expected batchCheck to be called with specific checks');
    }

    public function batchCheck(array $checks, ?string $connection = null): array
    {
        $this->batchCheckCalls[] = func_get_args();

        // Look for configured results
        foreach ($this->batchCheckResults as $expected) {
            if ($expected['checks'] === $checks) {
                return $expected['result'];
            }
        }

        // Default behavior - return false for all checks
        $results = [];

        foreach ($checks as $index => $check) {
            $results[] = ['allowed' => false];
        }

        return $results;
    }

    public function shouldReceiveBatchCheck(array $checks, array $result): self
    {
        $this->batchCheckResults[] = [
            'checks' => $checks,
            'result' => $result,
        ];

        return $this;
    }
}
