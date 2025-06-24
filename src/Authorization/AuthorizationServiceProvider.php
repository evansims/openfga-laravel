<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Authorization;

use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use OpenFGA\Laravel\Contracts\{AuthorizableUser, AuthorizationObject, AuthorizationType};
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;
use Override;

use function count;
use function gettype;
use function is_object;
use function is_scalar;
use function is_string;

/**
 * Service provider for OpenFGA authorization integration.
 */
final class AuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->registerOpenFgaGates();
        $this->registerGlobalHelpers();
    }

    /**
     * Register services.
     */
    #[Override]
    public function register(): void
    {
        // Register the OpenFGA Gate implementation
        $this->app->singleton(GateContract::class, static function (Container $app): OpenFgaGate {
            $manager = $app->make(OpenFgaManager::class);

            return new OpenFgaGate(
                $manager,
                $app,
                static fn () => Auth::user(),
            );
        });
    }

    /**
     * Resolve the object identifier for OpenFGA.
     *
     * @param mixed $resource
     *
     * @throws InvalidArgumentException
     */
    public function resolveObject($resource): string
    {
        // String in object:id format
        if (is_string($resource)) {
            return $resource;
        }

        // Model with authorization support
        if (is_object($resource) && method_exists($resource, 'authorizationObject')) {
            /** @var AuthorizationObject&object $resource */
            return $resource->authorizationObject();
        }

        // Model with authorization type method
        if (is_object($resource) && method_exists($resource, 'authorizationType') && method_exists($resource, 'getKey')) {
            /** @var AuthorizationType&Model&object $resource */
            $type = $resource->authorizationType();
            $key = ModelKeyHelper::stringId($resource);

            return $type . ':' . $key;
        }

        // Eloquent model fallback
        if (is_object($resource)) {
            /** @var object $resource */
            if (method_exists($resource, 'getTable') && method_exists($resource, 'getKey')) {
                /** @var Model $resource */
                $table = $resource->getTable();
                $key = ModelKeyHelper::stringId($resource);

                // Ensure table is a string

                return $table . ':' . $key;
            }
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($resource));
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param Authenticatable $user
     *
     * @throws InvalidArgumentException
     */
    public function resolveUserId($user): string
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

            if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
                return (string) $result;
            }

            throw new InvalidArgumentException('authorizationUser() must return a string or stringable value');
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            /** @var mixed $result */
            $result = $user->getAuthorizationUserId();

            if (is_string($result)) {
                return $result;
            }

            if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
                return (string) $result;
            }

            throw new InvalidArgumentException('getAuthorizationUserId() must return a string or stringable value');
        }

        $identifier = $user->getAuthIdentifier();

        if (null === $identifier || (! is_string($identifier) && ! is_numeric($identifier))) {
            throw new InvalidArgumentException('User identifier must be string or numeric');
        }

        return 'user:' . (string) $identifier;
    }

    /**
     * Register global authorization helpers.
     */
    private function registerGlobalHelpers(): void
    {
        // Global helper functions are defined in Helpers.php
        // This method is kept for backwards compatibility
    }

    /**
     * Register OpenFGA-specific gates.
     *
     * @throws BindingResolutionException
     */
    private function registerOpenFgaGates(): void
    {
        $gate = $this->app->make(GateContract::class);

        // Register a generic OpenFGA gate
        $gate->define('openfga', function (Authenticatable $user, string $relation, mixed $resource, ?string $connection = null): bool {
            $manager = $this->app->make(OpenFgaManager::class);

            $userId = $this->resolveUserId($user);
            $object = $this->resolveObject($resource);

            return $manager->check($userId, $relation, $object, [], [], $connection);
        });

        // Register wildcard gate for OpenFGA permissions
        $gate->before(function (?Authenticatable $user, string $ability, array $arguments): ?bool {
            // Check if this looks like an OpenFGA permission check
            if (str_starts_with($ability, 'openfga:')) {
                if (! $user instanceof Authenticatable) {
                    return false; // Not authenticated
                }

                $relation = str_replace('openfga:', '', $ability);
                $resource = $arguments[0] ?? null;

                // Guard against missing resource argument
                if (null === $resource) {
                    throw new InvalidArgumentException('Missing resource argument');
                }

                $connection = (1 < count($arguments) && is_string($arguments[1])) ? $arguments[1] : null;

                if (null !== $resource) {
                    $manager = $this->app->make(OpenFgaManager::class);

                    $userId = $this->resolveUserId($user);
                    $object = $this->resolveObject($resource);

                    return $manager->check($userId, $relation, $object, [], [], $connection);
                }
            }

            return null; // Let other gates handle this
        });
    }
}
