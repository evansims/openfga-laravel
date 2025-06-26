<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

/**
 * Contract for User models that integrate with OpenFGA authorization.
 *
 * Implement this interface on your User model to customize how users are
 * identified in OpenFGA. This allows you to use custom user identifiers
 * (like 'user:uuid' or 'employee:id') instead of the default numeric IDs.
 * Both methods should return the same value - two methods are provided
 * for backwards compatibility.
 *
 * @api
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
