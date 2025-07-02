<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use function sprintf;

/**
 * Exception thrown when an authorization model cannot be found.
 */
final class ModelNotFoundException extends AbstractOpenFgaException
{
    public static function noModelSpecified(): self
    {
        return new self('No authorization model ID specified in configuration');
    }

    public static function withId(string $modelId): self
    {
        return new self(sprintf("Authorization model with ID '%s' not found", $modelId));
    }
}
