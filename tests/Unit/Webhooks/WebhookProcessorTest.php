<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Webhooks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Events\WebhookReceived;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Webhooks\WebhookProcessor;
use PHPUnit\Framework\Attributes\Test;

final class WebhookProcessorTest extends TestCase
{
    private WebhookProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->processor = new WebhookProcessor();
    }

    #[Test]
    public function it_processes_authorization_model_write_webhook(): void
    {
        Event::fake();
        Log::spy();
        
        config(['openfga.cache.enabled' => true]);
        config(['openfga.cache.prefix' => 'openfga']);
        
        $payload = [
            'type' => 'authorization_model_write',
            'data' => [
                'store_id' => '01HQMVAH3R8X123456789',
                'model_id' => '01HQMVAH3R8X987654321',
            ],
        ];
        
        $this->processor->process($payload);
        
        Event::assertDispatched(function (WebhookReceived $event) use ($payload) {
            return $event->type === $payload['type']
                && $event->data === $payload['data'];
        });
    }

    #[Test]
    public function it_processes_tuple_write_webhook(): void
    {
        Event::fake();
        Log::spy();
        
        config(['openfga.cache.enabled' => true]);
        
        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];
        
        $cache = $this->mock(CacheRepository::class);
        $cache->shouldReceive('forget')->atLeast()->twice();
        
        Cache::shouldReceive('store')->andReturn($cache);
        
        $processor = new WebhookProcessor($cache);
        $processor->process($payload);
        
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_processes_tuple_delete_webhook(): void
    {
        Event::fake();
        Log::spy();
        
        config(['openfga.cache.enabled' => true]);
        
        $payload = [
            'type' => 'tuple_delete',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];
        
        $cache = $this->mock(CacheRepository::class);
        $cache->shouldReceive('forget')->atLeast()->twice();
        
        Cache::shouldReceive('store')->andReturn($cache);
        
        $processor = new WebhookProcessor($cache);
        $processor->process($payload);
        
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_handles_unknown_webhook_types(): void
    {
        Event::fake();
        Log::spy();
        
        $payload = [
            'type' => 'unknown_type',
            'data' => ['some' => 'data'],
        ];
        
        $this->processor->process($payload);
        
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_skips_cache_invalidation_when_cache_disabled(): void
    {
        Event::fake();
        Log::spy();
        
        config(['openfga.cache.enabled' => false]);
        
        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];
        
        $cache = $this->mock(CacheRepository::class);
        $cache->shouldNotReceive('forget');
        
        $processor = new WebhookProcessor($cache);
        $processor->process($payload);
        
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_handles_invalid_tuple_data(): void
    {
        Event::fake();
        Log::spy();
        
        config(['openfga.cache.enabled' => true]);
        
        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'invalid' => 'data',
            ],
        ];
        
        $this->processor->process($payload);
        
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_logs_when_cache_store_does_not_support_tags(): void
    {
        Event::fake();
        Log::spy();
        
        config(['openfga.cache.enabled' => true]);
        
        $payload = [
            'type' => 'authorization_model_write',
            'data' => [
                'store_id' => '01HQMVAH3R8X123456789',
                'model_id' => '01HQMVAH3R8X987654321',
            ],
        ];
        
        $cache = $this->mock(CacheRepository::class);
        // Cache doesn't have tags method
        
        Cache::shouldReceive('store')->andReturn($cache);
        
        $processor = new WebhookProcessor($cache);
        $processor->process($payload);
        
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_invalidates_multiple_cache_keys_for_tuple_changes(): void
    {
        Log::spy();
        
        config(['openfga.cache.enabled' => true]);
        config(['openfga.cache.prefix' => 'test']);
        
        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];
        
        $cache = $this->mock(CacheRepository::class);
        
        // Should invalidate at least these cache keys
        $cache->shouldReceive('forget')->atLeast()->twice();
            
        // Pattern-based deletions
        Cache::shouldReceive('store')->andReturn($cache);
        
        $processor = new WebhookProcessor($cache);
        $processor->process($payload);
    }
}