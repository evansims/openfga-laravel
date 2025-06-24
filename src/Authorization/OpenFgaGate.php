<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Authorization;

use Exception;
use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\{BindingResolutionException, Container};
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\{AuthorizableUser, AuthorizationObject};
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;
use Override;
use UnitEnum;

use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * OpenFGA-powered Gate implementation that integrates with Laravel's authorization system.
 *
 * @template TUser of Authenticatable&Model
 */
final class OpenFgaGate extends Gate
{
    /**
     * Create a new OpenFGA Gate instance.
     *
     * @param OpenFgaManager               $manager
     * @param Container                    $container
     * @param callable(): ?Authenticatable $userResolver
     */
    public function __construct(
        private readonly OpenFgaManager $manager,
        Container $container,
        callable $userResolver,
    ) {
        parent::__construct($container, $userResolver);
    }

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @param iterable<mixed>|string|UnitEnum          $abilities
     * @param array<int|Model|string>|int|Model|string $arguments
     * @param Authenticatable|null                     $user
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Override]
    public function check($abilities, $arguments = [], $user = null): bool
    {
        // Handle single ability
        if (! is_string($abilities)) {
            // Fall back to Laravel's default behavior for non-OpenFGA checks
            return parent::check($abilities, $arguments);
        }

        // First check if this is an OpenFGA permission check
        if ($this->isOpenFgaPermission($arguments)) {
            return $this->checkOpenFgaPermission($abilities, $arguments, $user);
        }

        // Fall back to Laravel's default behavior for non-OpenFGA checks
        return parent::check($abilities, $arguments);
    }

    /**
     * Resolve the user from the user resolver.
     *
     * @return Authenticatable&Model|null
     */
    #[Override]
    protected function resolveUser(): ?Authenticatable
    {
        /** @var mixed $user */
        $user = parent::resolveUser();

        if ($user instanceof Authenticatable) {
            return $user;
        }

        return null;
    }

    /**
     * Check OpenFGA permission.
     *
     * @param string                                   $ability
     * @param array<int|Model|string>|int|Model|string $arguments
     * @param Authenticatable|null                     $user
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    private function checkOpenFgaPermission(string $ability, $arguments, ?Authenticatable $user = null): bool
    {
        $user ??= $this->resolveUser();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        /** @var Authenticatable $user */
        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($arguments);

        if (null === $object) {
            return false;
        }

        return $this->manager->check($userId, $ability, $object);
    }

    /**
     * Determine if this is an OpenFGA permission check.
     *
     * @param array<int|Model|string>|int|Model|string $arguments
     */
    private function isOpenFgaPermission($arguments): bool
    {
        // Check if we have a clear object identifier in arguments
        $arguments = is_array($arguments) ? $arguments : [$arguments];

        foreach ($arguments as $argument) {
            if (is_string($argument) && str_contains($argument, ':')) {
                return true; // Looks like object:id format
            }

            if (is_object($argument) && $argument instanceof Model && method_exists($argument, 'authorizationObject')) {
                return true; // Model with authorization support
            }
        }

        return false;
    }

    /**
     * Resolve the object from arguments.
     *
     * @param array<int|Model|string>|int|Model|string $arguments
     */
    private function resolveObject($arguments): ?string
    {
        $arguments = is_array($arguments) ? $arguments : [$arguments];

        foreach ($arguments as $argument) {
            // String in object:id format
            if (is_string($argument) && str_contains($argument, ':')) {
                return $argument;
            }

            // Model with authorization support
            if (method_exists($argument, 'authorizationObject')) {
                /** @var AuthorizationObject&Model $argument */
                return $argument->authorizationObject();
            }

            // Model with authorization type method
            if (method_exists($argument, 'authorizationType') && method_exists($argument, 'getKey')) {
                return $this->resolveModelObject($argument);
            }

            // Check if argument is a valid Eloquent Model instance with getTable() and getKey() methods
            if (method_exists($argument, 'getTable') && method_exists($argument, 'getKey')) {
                $table = $argument->getTable();
                $key = ModelKeyHelper::stringId($argument);

                return sprintf('%s:%s', $table, $key);
            }
        }

        return null;
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param Authenticatable $user
     */
    private function resolveUserId(Authenticatable $user): string
    {
        // Check if user implements our AuthorizableUser interface
        if ($user instanceof AuthorizableUser) {
            return $user->authorizationUser();
        }

        // Legacy support: check for method without interface
        if (method_exists($user, 'authorizationUser')) {
            $result = $user->authorizationUser();

            if (is_string($result)) {
                return $result;
            }
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            $result = $user->getAuthorizationUserId();

            if (is_string($result)) {
                return $result;
            }
        }

        $identifier = $user->getAuthIdentifier();

        if (null === $identifier || (! is_string($identifier) && ! is_numeric($identifier))) {
            return 'user:unknown';
        }

        return 'user:' . (string) $identifier;
    }
}
