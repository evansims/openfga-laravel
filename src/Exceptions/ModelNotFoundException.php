<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

/**
 * Exception thrown when an authorization model cannot be found
 */
final class ModelNotFoundException extends OpenFgaException
{
    public static function withId(string $modelId): self
    {
        return new self("Authorization model with ID '{$modelId}' not found");
    }

    public static function noModelSpecified(): self
    {
        return new self('No authorization model ID specified in configuration');
    }
}