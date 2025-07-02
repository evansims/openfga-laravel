<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Exceptions;

use function sprintf;

/**
 * Exception thrown when an OpenFGA store cannot be found.
 */
final class StoreNotFoundException extends AbstractOpenFgaException
{
    public static function noStoreSpecified(): self
    {
        return new self('No store ID specified in configuration');
    }

    public static function withId(string $storeId): self
    {
        return new self(sprintf("OpenFGA store with ID '%s' not found", $storeId));
    }
}
