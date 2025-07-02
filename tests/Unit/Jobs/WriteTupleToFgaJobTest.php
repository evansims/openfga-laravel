<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Jobs;

use Illuminate\Support\Facades\Log;
use Mockery;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\Jobs\WriteTupleToFgaJob;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WriteTupleToFgaJobTest extends TestCase
{
    #[Test]
    public function it_grants_permission_when_operation_is_write(): void
    {
        $manager = Mockery::mock(OpenFgaManager::class);
        $manager->shouldReceive('grant')
            ->once()
            ->with('user:123', 'editor', 'document:456')
            ->andReturn(true);
        
        OpenFga::swap($manager);
        Log::spy();
        
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'write',
            openfgaConnection: null
        );
        
        $job->handle();
        
        Log::shouldHaveReceived('debug')
            ->once()
            ->with('Successfully wrote tuple to OpenFGA', [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
                'connection' => null,
            ]);
    }
    
    #[Test]
    public function it_revokes_permission_when_operation_is_delete(): void
    {
        $manager = Mockery::mock(OpenFgaManager::class);
        $manager->shouldReceive('revoke')
            ->once()
            ->with('user:123', 'editor', 'document:456')
            ->andReturn(true);
        
        OpenFga::swap($manager);
        Log::spy();
        
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'delete',
            openfgaConnection: null
        );
        
        $job->handle();
        
        Log::shouldHaveReceived('debug')
            ->once()
            ->with('Successfully deleted tuple from OpenFGA', [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
                'connection' => null,
            ]);
    }
    
    #[Test]
    public function it_sets_connection_when_specified(): void
    {
        $manager = Mockery::mock(OpenFgaManager::class);
        $manager->shouldReceive('setConnection')
            ->once()
            ->with('tenant_a')
            ->andReturnSelf();
        $manager->shouldReceive('grant')
            ->once()
            ->andReturn(true);
        
        OpenFga::swap($manager);
        Log::spy();
        
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'write',
            openfgaConnection: 'tenant_a'
        );
        
        $job->handle();
    }
    
    #[Test]
    public function it_throws_exception_when_grant_fails(): void
    {
        $manager = Mockery::mock(OpenFgaManager::class);
        $manager->shouldReceive('grant')
            ->once()
            ->andReturn(false);
        
        OpenFga::swap($manager);
        Log::spy();
        
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'write',
            openfgaConnection: null
        );
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to grant permission: user:123 editor document:456');
        
        $job->handle();
    }
    
    #[Test]
    public function it_throws_exception_when_revoke_fails(): void
    {
        $manager = Mockery::mock(OpenFgaManager::class);
        $manager->shouldReceive('revoke')
            ->once()
            ->andReturn(false);
        
        OpenFga::swap($manager);
        Log::spy();
        
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'delete',
            openfgaConnection: null
        );
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to revoke permission: user:123 editor document:456');
        
        $job->handle();
    }
    
    #[Test]
    public function it_logs_errors_and_rethrows_exceptions(): void
    {
        $exception = new \Exception('OpenFGA connection failed');
        
        $manager = Mockery::mock(OpenFgaManager::class);
        $manager->shouldReceive('grant')
            ->once()
            ->andThrow($exception);
        
        OpenFga::swap($manager);
        Log::spy();
        
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'write',
            openfgaConnection: null
        );
        
        try {
            $job->handle();
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertSame($exception, $e);
        }
        
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Failed to write tuple to OpenFGA', [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
                'operation' => 'write',
                'connection' => null,
                'error' => 'OpenFGA connection failed',
            ]);
    }
    
    #[Test]
    public function it_has_correct_tags(): void
    {
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'write',
            openfgaConnection: null
        );
        
        $tags = $job->tags();
        
        expect($tags)
            ->toBeArray()
            ->toContain('openfga')
            ->toContain('openfga:write')
            ->toContain('openfga:object:document:456');
    }
    
    #[Test]
    public function it_has_correct_backoff_array(): void
    {
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'write',
            openfgaConnection: null
        );
        
        expect($job->backoffArray())
            ->toBe([10, 30, 60]);
    }
    
    #[Test]
    public function it_has_correct_retry_configuration(): void
    {
        $job = new WriteTupleToFgaJob(
            user: 'user:123',
            relation: 'editor',
            object: 'document:456',
            operation: 'write',
            openfgaConnection: null
        );
        
        expect($job->tries)->toBe(3);
        expect($job->maxExceptions)->toBe(3);
        expect($job->backoff)->toBe(10);
    }
}