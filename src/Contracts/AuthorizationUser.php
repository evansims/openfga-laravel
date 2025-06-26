<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

/**
 * Interface for user models that can provide a custom authorization user identifier.
 *
 * @api
 */
interface AuthorizationUser
{
    /**
     * Get the authorization user identifier in format "type:id".
     */
    public function authorizationUser(): string;
}
