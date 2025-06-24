<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

/**
 * Interface for models that can provide a custom authorization object identifier.
 */
interface AuthorizationObject
{
    /**
     * Get the authorization object identifier in format "type:id".
     */
    public function authorizationObject(): string;
}
