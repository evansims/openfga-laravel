<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Webhooks;

use OpenFGA\Laravel\Abstracts\AbstractWebhookProcessor;

/**
 * Processes incoming webhooks from OpenFGA.
 *
 * This service handles the logic of parsing webhook payloads and
 * taking appropriate actions such as invalidating caches and
 * dispatching events.
 *
 * @internal
 */
final class WebhookProcessor extends AbstractWebhookProcessor
{
}
