<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Controllers;

use Exception;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use OpenFGA\Laravel\Webhooks\WebhookProcessor;
use Symfony\Component\HttpFoundation\Response;

use function is_array;
use function is_string;

/**
 * Controller to handle incoming webhooks from OpenFGA.
 *
 * This controller receives webhook notifications from OpenFGA when
 * authorization data changes, allowing the application to invalidate
 * caches and react to permission changes in real-time.
 */
final class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookProcessor $processor,
    ) {
    }

    /**
     * Handle incoming OpenFGA webhook.
     *
     * @param Request $request
     */
    public function handle(Request $request): JsonResponse
    {
        // Verify webhook signature if configured
        if (! $this->verifySignature($request)) {
            Log::warning('OpenFGA webhook signature verification failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        // Get the webhook payload
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        // Validate payload structure
        if (! $this->validatePayload($payload)) {
            Log::warning('OpenFGA webhook invalid payload', [
                'payload' => $payload,
            ]);

            return response()->json(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Process the webhook
            $this->processor->process($payload);

            return response()->json(['status' => 'success'], Response::HTTP_OK);
        } catch (Exception $exception) {
            Log::error('OpenFGA webhook processing failed', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json(['error' => 'Processing failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Validate webhook payload structure.
     *
     * @param mixed $payload
     */
    private function validatePayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        // Check required fields
        if (! isset($payload['type']) || ! is_string($payload['type'])) {
            return false;
        }

        return isset($payload['data']) && is_array($payload['data']);
    }

    /**
     * Verify webhook signature.
     *
     * @param Request $request
     */
    private function verifySignature(Request $request): bool
    {
        $secret = config('openfga.webhooks.secret');

        // If no secret is configured, skip verification
        if (! is_string($secret) || '' === $secret) {
            return true;
        }

        $signature = $request->header('X-OpenFGA-Signature');

        if (! is_string($signature)) {
            return false;
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
