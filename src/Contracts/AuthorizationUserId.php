<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

/**
 * Interface for user models that can provide a custom authorization user ID.
 *
 * @api
 */
interface AuthorizationUserId
{
    /**
     * Get the authorization user ID (without type prefix).
     */
    public function getAuthorizationUserId(): string;
}
