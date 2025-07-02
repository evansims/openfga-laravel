<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a webhook is received from OpenFGA.
 */
final class WebhookReceived
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $type
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data
    ) {
    }
}