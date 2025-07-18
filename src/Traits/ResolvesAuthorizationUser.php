<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

use function is_string;

/**
 * Trait for resolving user identifiers for OpenFGA.
 */
trait ResolvesAuthorizationUser
{
    /**
     * Resolve the user ID for OpenFGA from an authenticatable user.
     *
     * @param Authenticatable $user The authenticated user
     *
     * @throws InvalidArgumentException If identifier cannot be resolved
     *
     * @return string The user identifier for OpenFGA
     */
    protected function resolveUserIdentifier(Authenticatable $user): string
    {
        // Check for custom authorization user methods
        if (method_exists($user, 'authorizationUser')) {
            /** @var mixed $result */
            $result = $user->authorizationUser();

            if (is_string($result) || is_numeric($result)) {
                return (string) $result;
            }

            throw new InvalidArgumentException('authorizationUser() must return a string or numeric value');
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            /** @var mixed $result */
            $result = $user->getAuthorizationUserId();

            if (is_string($result) || is_numeric($result)) {
                return (string) $result;
            }

            throw new InvalidArgumentException('getAuthorizationUserId() must return a string or numeric value');
        }

        // Default to user:{id}
        /** @var mixed $identifier */
        $identifier = $user->getAuthIdentifier();

        if (is_string($identifier) || is_numeric($identifier)) {
            return 'user:' . (string) $identifier;
        }

        throw new InvalidArgumentException('User identifier must be scalar');
    }
}
