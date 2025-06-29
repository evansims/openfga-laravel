<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Support;

use OpenFGA\Laravel\Query\AuthorizationQuery;
use PHPUnit\Framework\Assert;

/**
 * Mock implementation of OpenFgaManager methods for testing
 * Since OpenFgaManager is final, we can't extend it, but we can use duck typing
 */
class MockOpenFgaManager
{
    private array $expectations = [];
    private array $calls = [];
    
    public function shouldReceive(string $method): MockExpectation
    {
        $expectation = new MockExpectation($method, $this);
        $this->expectations[] = $expectation;
        return $expectation;
    }
    
    public function recordCall(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
    
    public function findExpectation(string $method, array $args)
    {
        foreach ($this->expectations as $expectation) {
            if ($expectation->matches($method, $args)) {
                return $expectation->getReturn();
            }
        }
        
        throw new \RuntimeException("No expectation set for {$method} with args: " . json_encode($args));
    }
    
    public function check(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null
    ): bool {
        $args = func_get_args();
        $this->recordCall('check', $args);
        return $this->findExpectation('check', $args);
    }
    
    public function grant(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        ?string $connection = null
    ): bool {
        $args = func_get_args();
        $this->recordCall('grant', $args);
        return $this->findExpectation('grant', $args);
    }
    
    public function revoke(
        string $user,
        string $relation,
        string $object,
        array $contextualTuples = [],
        ?string $connection = null
    ): bool {
        $args = func_get_args();
        $this->recordCall('revoke', $args);
        return $this->findExpectation('revoke', $args);
    }
    
    public function listUsers(
        string $object,
        string $relation,
        array $userTypes = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null
    ): array {
        $args = func_get_args();
        $this->recordCall('listUsers', $args);
        return $this->findExpectation('listUsers', $args);
    }
    
    public function listRelations(
        string $user,
        string $object,
        array $relations = [],
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null
    ): array {
        $args = func_get_args();
        $this->recordCall('listRelations', $args);
        return $this->findExpectation('listRelations', $args);
    }
    
    public function listObjects(
        string $user,
        string $relation,
        string $type,
        array $contextualTuples = [],
        array $context = [],
        ?string $connection = null
    ): array {
        $args = func_get_args();
        $this->recordCall('listObjects', $args);
        return $this->findExpectation('listObjects', $args);
    }
    
    public function query(?string $connection = null): AuthorizationQuery
    {
        $args = func_get_args();
        $this->recordCall('query', $args);
        return $this->findExpectation('query', $args);
    }
    
    public function verifyExpectations(): void
    {
        foreach ($this->expectations as $expectation) {
            $expectation->verify($this->calls);
        }
    }
}

/**
 * Helper class to build mock expectations
 */
class MockExpectation
{
    private string $method;
    private MockOpenFgaManager $mock;
    private ?array $withArgs = null;
    private $returnValue = null;
    private int $times = 1;
    private bool $shouldVerifyTimes = false;
    
    public function __construct(string $method, MockOpenFgaManager $mock)
    {
        $this->method = $method;
        $this->mock = $mock;
    }
    
    public function with(...$args): self
    {
        $this->withArgs = $args;
        return $this;
    }
    
    public function once(): self
    {
        $this->times = 1;
        $this->shouldVerifyTimes = true;
        return $this;
    }
    
    public function times(int $times): self
    {
        $this->times = $times;
        $this->shouldVerifyTimes = true;
        return $this;
    }
    
    public function andReturn($value): self
    {
        $this->returnValue = $value;
        return $this;
    }
    
    public function andReturnSelf(): self
    {
        $this->returnValue = $this->mock;
        return $this;
    }
    
    public function matches(string $method, array $args): bool
    {
        if ($this->method !== $method) {
            return false;
        }
        
        if ($this->withArgs === null) {
            return true;
        }
        
        // Simple argument matching - just check the important args
        $importantArgs = array_slice($args, 0, count($this->withArgs));
        return $importantArgs === $this->withArgs;
    }
    
    public function getReturn()
    {
        return $this->returnValue;
    }
    
    public function verify(array $calls): void
    {
        if (!$this->shouldVerifyTimes) {
            return;
        }
        
        $matchingCalls = 0;
        foreach ($calls as $call) {
            if ($call['method'] === $this->method) {
                if ($this->withArgs === null || $this->matches($this->method, $call['args'])) {
                    $matchingCalls++;
                }
            }
        }
        
        Assert::assertEquals(
            $this->times,
            $matchingCalls,
            "Expected {$this->method} to be called {$this->times} times, but was called {$matchingCalls} times"
        );
    }
}