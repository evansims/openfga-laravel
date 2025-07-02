<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Unit\Http\Controllers;

use Exception;
use Illuminate\Support\Facades\{Event, Log};
use OpenFGA\Laravel\Abstracts\AbstractWebhookProcessor;
use OpenFGA\Laravel\Events\WebhookReceived;
use OpenFGA\Laravel\Tests\TestCase;
use OpenFGA\Laravel\Webhooks\{WebhookServiceProvider};
use Override;
use PHPUnit\Framework\Attributes\Test;

final class WebhookControllerTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Enable webhooks for testing
        config(['openfga.webhooks.enabled' => true]);

        // Register and boot the webhook service provider
        $provider = $this->app->register(WebhookServiceProvider::class);
        $provider->boot();
    }

    #[Test]
    public function it_accepts_valid_signature(): void
    {
        Event::fake();

        config(['openfga.webhooks.secret' => 'test-secret']);

        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];

        $signature = hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->postJson('/openfga/webhook', $payload, [
            'X-OpenFGA-Signature' => $signature,
        ]);

        $response->assertOk();
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_handles_processor_exceptions(): void
    {
        Log::shouldReceive('error')->once();

        $this->mock(AbstractWebhookProcessor::class)
            ->shouldReceive('process')
            ->andThrow(new Exception('Processing error'));

        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];

        $response = $this->postJson('/openfga/webhook', $payload);

        $response->assertServerError();
        $response->assertJson(['error' => 'Processing failed']);
    }

    #[Test]
    public function it_handles_valid_webhook_request(): void
    {
        Event::fake();

        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];

        $response = $this->postJson('/openfga/webhook', $payload);

        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        Event::assertDispatched(WebhookReceived::class);
    }

    #[Test]
    public function it_rejects_invalid_payload_structure(): void
    {
        Log::shouldReceive('warning')->once();

        $response = $this->postJson('/openfga/webhook', [
            'invalid' => 'payload',
        ]);

        $response->assertBadRequest();
        $response->assertJson(['error' => 'Invalid payload']);
    }

    #[Test]
    public function it_rejects_invalid_signature_when_secret_configured(): void
    {
        Log::shouldReceive('warning')->once();

        config(['openfga.webhooks.secret' => 'test-secret']);

        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];

        $response = $this->postJson('/openfga/webhook', $payload, [
            'X-OpenFGA-Signature' => 'invalid-signature',
        ]);

        $response->assertUnauthorized();
        $response->assertJson(['error' => 'Invalid signature']);
    }

    #[Test]
    public function it_rejects_missing_data_field(): void
    {
        Log::shouldReceive('warning')->once();

        $response = $this->postJson('/openfga/webhook', [
            'type' => 'tuple_write',
        ]);

        $response->assertBadRequest();
        $response->assertJson(['error' => 'Invalid payload']);
    }

    #[Test]
    public function it_rejects_missing_type_field(): void
    {
        Log::shouldReceive('warning')->once();

        $response = $this->postJson('/openfga/webhook', [
            'data' => ['some' => 'data'],
        ]);

        $response->assertBadRequest();
        $response->assertJson(['error' => 'Invalid payload']);
    }

    #[Test]
    public function it_skips_signature_verification_when_no_secret_configured(): void
    {
        Event::fake();

        config(['openfga.webhooks.secret' => null]);

        $payload = [
            'type' => 'tuple_write',
            'data' => [
                'user' => 'user:123',
                'relation' => 'editor',
                'object' => 'document:456',
            ],
        ];

        // No signature header provided
        $response = $this->postJson('/openfga/webhook', $payload);

        $response->assertOk();
        Event::assertDispatched(WebhookReceived::class);
    }
}
