<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

/**
 * Interface for users that can be authorized with OpenFGA.
 */
interface AuthorizableUser
{
    /**
     * Get the user identifier for OpenFGA authorization.
     */
    public function authorizationUser(): string;

    /**
     * Alternative method to get the authorization user ID.
     */
    public function getAuthorizationUserId(): string;
}
