<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use Override;
use PHPUnit\Framework\AssertionFailedError;

use function func_get_args;

/**
 * Extended test double that includes batchCheck support.
 */
final class ExtendedTestOpenFgaManager extends TestOpenFgaManager
{
    public $batchCheckCalls = [];

    private array $batchCheckResults = [];

    public function assertBatchCheckCalled(array $expectedChecks): void
    {
        foreach ($this->batchCheckCalls as $batchCheckCall) {
            if ($batchCheckCall[0] === $expectedChecks) {
                return;
            }
        }

        throw new AssertionFailedError(message: 'Expected batchCheck to be called with specific checks');
    }

    #[Override]
    public function batchCheck(array $checks, ?string $connection = null): array
    {
        $this->batchCheckCalls[] = func_get_args();

        // Look for configured results
        foreach ($this->batchCheckResults as $batchCheckResult) {
            if ($batchCheckResult['checks'] === $checks) {
                return $batchCheckResult['result'];
            }
        }

        // Default behavior - return false for all checks
        $results = [];

        foreach ($checks as $check) {
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
