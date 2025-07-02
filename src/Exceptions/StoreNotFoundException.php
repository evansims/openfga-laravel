<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

/**
 * Exception thrown when an OpenFGA store cannot be found
 */
final class StoreNotFoundException extends OpenFgaException
{
    public static function withId(string $storeId): self
    {
        return new self("OpenFGA store with ID '{$storeId}' not found");
    }

    public static function noStoreSpecified(): self
    {
        return new self('No store ID specified in configuration');
    }
}