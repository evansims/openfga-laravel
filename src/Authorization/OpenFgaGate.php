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
use OpenFGA\Laravel\Contracts\{AuthorizableUser, AuthorizationObject, AuthorizationType, ManagerInterface, OpenFgaGateInterface};
use Override;
use UnitEnum;

use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Laravel Gate implementation powered by OpenFGA for fine-grained authorization.
 *
 * This Gate seamlessly integrates OpenFGA with Laravel's built-in authorization
 * system, allowing you to use familiar Laravel syntax (Gate::allows, @can) while
 * leveraging OpenFGA's relationship-based access control. It automatically detects
 * whether to use OpenFGA or fall back to traditional Gate policies based on the
 * arguments provided, making migration from Laravel's default authorization smooth.
 *
 * @template TUser of Authenticatable&Model
 */
final class OpenFgaGate extends Gate implements OpenFgaGateInterface
{
    /**
     * Create a new OpenFGA Gate instance.
     *
     * @param ManagerInterface             $manager
     * @param Container                    $container
     * @param callable(): ?Authenticatable $userResolver
     */
    public function __construct(
        private readonly ManagerInterface $manager,
        Container $container,
        callable $userResolver,
    ) {
        parent::__construct($container, $userResolver);
    }

    /**
     * Determine if all of the given abilities should be granted for the current user.
     *
     * Supports both Laravel's native abilities and OpenFGA permission checks.
     * OpenFGA checks are identified by the presence of authorization objects in arguments.
     *
     * @param iterable<mixed>|string|UnitEnum $abilities Single ability string or iterable of abilities
     * @param array<mixed>|mixed              $arguments Authorization object(s), model instances, or traditional gate arguments
     * @param Authenticatable|null            $user      User to check permissions for (optional, uses current user if null)
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return bool True if all abilities are granted, false otherwise
     */
    #[Override]
    public function check($abilities, $arguments = [], $user = null): bool
    {
        // Handle single string ability first to avoid iterable/string confusion
        if (is_string($abilities)) {
            // For string abilities, check if this is an OpenFGA permission check
            if ($this->isOpenFgaPermission($arguments)) {
                /** @var array<mixed>|object|string $arguments */
                return $this->checkOpenFgaPermission($abilities, $arguments, $user);
            }

            // Fall back to Laravel's default behavior for non-OpenFGA checks
            return parent::check($abilities, $arguments);
        }

        // Handle non-string abilities (arrays, Collections, UnitEnum, etc.)
        return parent::check($abilities, $arguments);
    }

    /**
     * Check a specific OpenFGA permission.
     *
     * @param string                     $ability   The OpenFGA relation/permission to check
     * @param array<mixed>|object|string $arguments Object identifier, model instance, or arguments array
     * @param Authenticatable|null       $user      User to check permissions for
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return bool True if permission is granted, false otherwise
     */
    #[Override]
    public function checkOpenFgaPermission(string $ability, mixed $arguments, ?Authenticatable $user = null): bool
    {
        $user ??= $this->resolveUser();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        $userId = $this->resolveUserId($user);
        $object = $this->resolveObject($arguments);

        if (null === $object) {
            return false;
        }

        return $this->manager->check($userId, $ability, $object);
    }

    /**
     * Determine if arguments represent an OpenFGA permission check.
     *
     * @param  mixed $arguments Arguments to analyze
     * @return bool  True if this appears to be an OpenFGA check
     */
    #[Override]
    public function isOpenFgaPermission(mixed $arguments): bool
    {
        // Check if we have a clear object identifier in arguments
        $arguments = is_array($arguments) ? $arguments : [$arguments];

        /** @var mixed $argument */
        foreach ($arguments as $argument) {
            if (is_string($argument) && str_contains($argument, ':')) {
                return true; // Looks like object:id format
            }

            if (is_object($argument) && $argument instanceof Model) {
                // Check if it has authorization methods (don't count basic getTable)
                if (method_exists($argument, 'authorizationObject')
                    || $argument instanceof AuthorizationType) {
                    return true; // Model with authorization support
                }

                // For basic Eloquent models without authorization interfaces,
                // still consider them as OpenFGA permissions since they can be used
                // Note: Model already has getTable() and getKey() methods
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the user from the user resolver.
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
     * Resolve the object from arguments.
     *
     * @param mixed $arguments
     */
    private function resolveObject(mixed $arguments): ?string
    {
        $arguments = is_array($arguments) ? $arguments : [$arguments];

        /** @var mixed $argument */
        foreach ($arguments as $argument) {
            // String in object:id format
            if (is_string($argument) && str_contains($argument, ':')) {
                return $argument;
            }

            // Model with authorization support
            if (is_object($argument) && method_exists($argument, 'authorizationObject')) {
                /** @var AuthorizationObject&Model $argument */
                return $argument->authorizationObject();
            }

            // Model with authorization type method
            if (is_object($argument) && $argument instanceof Model && $argument instanceof AuthorizationType) {
                /** @var AuthorizationType&Model $argument */
                $authType = $argument->authorizationType();
                $key = $argument->getKey();

                if (null === $key) {
                    continue;
                }

                if (! is_string($key) && ! is_numeric($key)) {
                    continue;
                }

                return sprintf('%s:%s', $authType, (string) $key);
            }

            // Check if argument is a valid Eloquent Model instance with getTable() and getKey() methods
            if (is_object($argument) && $argument instanceof Model) {
                $table = $argument->getTable();

                /** @var mixed $key */
                $key = $argument->getKey();

                if (null === $key || (! is_string($key) && ! is_numeric($key))) {
                    continue; // Skip models with invalid keys
                }

                return sprintf('%s:%s', $table, (string) $key);
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
            /** @var mixed $result */
            $result = $user->authorizationUser();

            if (is_string($result)) {
                return $result;
            }
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            /** @var mixed $result */
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
