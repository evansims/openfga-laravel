<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Listeners;

use OpenFGA\Laravel\Events\{PermissionChanged, PermissionChecked, PermissionGranted, PermissionRevoked};
use OpenFGA\Laravel\Webhooks\WebhookManager;

final readonly class WebhookEventListener
{
    public function __construct(
        private WebhookManager $webhookManager,
    ) {
    }

    /**
     * Handle permission checked events (optional, can be noisy).
     *
     * @param PermissionChecked $event
     */
    public function handlePermissionChecked(PermissionChecked $event): void
    {
        // Only send webhooks for checked events if explicitly configured
        if (! config('openfga.webhooks.send_check_events', false)) {
            return;
        }

        $this->webhookManager->notifyPermissionChange(
            new PermissionChanged(
                user: $event->user,
                relation: $event->relation,
                object: $event->object,
                action: 'checked',
                metadata: [
                    'result' => $event->allowed,
                    'checked_at' => now()->toIso8601String(),
                ],
            ),
        );
    }

    /**
     * Handle permission granted events.
     *
     * @param PermissionGranted $event
     */
    public function handlePermissionGranted(PermissionGranted $event): void
    {
        $this->webhookManager->notifyPermissionChange(
            new PermissionChanged(
                user: $event->user,
                relation: $event->relation,
                object: $event->object,
                action: 'granted',
                metadata: ['granted_at' => now()->toIso8601String()],
            ),
        );
    }

    /**
     * Handle permission revoked events.
     *
     * @param PermissionRevoked $event
     */
    public function handlePermissionRevoked(PermissionRevoked $event): void
    {
        $this->webhookManager->notifyPermissionChange(
            new PermissionChanged(
                user: $event->user,
                relation: $event->relation,
                object: $event->object,
                action: 'revoked',
                metadata: ['revoked_at' => now()->toIso8601String()],
            ),
        );
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param mixed $events
     */
    public function subscribe($events): array
    {
        return [
            PermissionGranted::class => 'handlePermissionGranted',
            PermissionRevoked::class => 'handlePermissionRevoked',
            PermissionChecked::class => 'handlePermissionChecked',
        ];
    }
}
