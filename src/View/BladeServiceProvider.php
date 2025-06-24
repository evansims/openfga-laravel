<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Auth, Blade};
use Illuminate\Support\ServiceProvider;
use Illuminate\View\{Factory, View};
use InvalidArgumentException;
use OpenFGA\Laravel\Contracts\AuthorizationType;
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;

use function gettype;
use function is_callable;
use function is_object;
use function is_string;

/**
 * Service provider for OpenFGA Blade directives and view helpers.
 */
final class BladeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        $this->registerBladeDirectives();
        $this->registerViewComposer();
    }

    /**
     * Check blade permission (public method for use by directives).
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     */
    public function checkBladePermission(string $relation, $object, ?string $connection = null): bool
    {
        if (! Auth::check()) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $user = Auth::user();

        if (null === $user) {
            return false;
        }

        $userId = $this->resolveUserId($user);
        $objectId = $this->resolveObject($object);

        /* @phpstan-ignore-next-line */
        return (bool) $manager->connection($connection)->check($userId, $relation, $objectId);
    }

    /**
     * Resolve the object identifier for OpenFGA.
     *
     * @param mixed $object
     *
     * @throws InvalidArgumentException
     */
    public function resolveObject($object): string
    {
        // String in object:id format
        if (is_string($object)) {
            return $object;
        }

        // Model with authorization support
        if (is_object($object) && method_exists($object, 'authorizationObject')) {
            $result = $object->authorizationObject();

            if (is_string($result)) {
                return $result;
            }
        }

        // Model with authorization type method
        if (is_object($object) && $object instanceof Model && $object instanceof AuthorizationType) {
            /** @var AuthorizationType&Model $object */
            $type = $object->authorizationType();
            $key = ModelKeyHelper::stringId($object);

            return $type . ':' . $key;
        }

        // Eloquent model fallback
        if (is_object($object) && $object instanceof Model) {
            $table = $object->getTable();
            $key = ModelKeyHelper::stringId($object);

            return $table . ':' . $key;
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($object));
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param Authenticatable $user The authenticated user
     *
     * @throws InvalidArgumentException If user identifier cannot be resolved
     *
     * @return string The user identifier for OpenFGA
     */
    public function resolveUserId(Authenticatable $user): string
    {
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

        if (is_string($identifier) || is_numeric($identifier)) {
            return 'user:' . $identifier;
        }

        throw new InvalidArgumentException('Unable to resolve user identifier');
    }

    /**
     * Register OpenFGA Blade directives.
     */
    private function registerBladeDirectives(): void
    {
        // @openfgacan directive
        Blade::if('openfgacan', function (string $relation, $object, ?string $connection = null): bool {
            if (! Auth::check()) {
                return false;
            }

            $manager = app(OpenFgaManager::class);
            $user = Auth::user();

            if (null === $user) {
                return false;
            }

            $userId = $this->resolveUserId($user);
            $objectId = $this->resolveObject($object);

            /* @phpstan-ignore-next-line */
            return (bool) $manager->connection($connection)->check($userId, $relation, $objectId);
        });

        // @openfgacannot directive (opposite of @openfgacan)
        Blade::if('openfgacannot', function (string $relation, $object, ?string $connection = null): bool {
            $provider = $this->app->make(BladeServiceProvider::class);

            return ! $provider->checkBladePermission($relation, $object, $connection);
        });

        // @openfgacanany directive - check if user has any of the given permissions
        Blade::if('openfgacanany', function (array $relations, $object, ?string $connection = null): bool {
            if (! Auth::check()) {
                return false;
            }

            foreach ($relations as $relation) {
                if (! is_string($relation)) {
                    continue;
                }

                $provider = $this->app->make(BladeServiceProvider::class);

                if ($provider->checkBladePermission($relation, $object, $connection)) {
                    return true;
                }
            }

            return false;
        });

        // @openfgacanall directive - check if user has all of the given permissions
        Blade::if('openfgacanall', function (array $relations, $object, ?string $connection = null): bool {
            if (! Auth::check()) {
                return false;
            }

            foreach ($relations as $relation) {
                if (! is_string($relation)) {
                    return false;
                }

                $provider = $this->app->make(BladeServiceProvider::class);

                if (! $provider->checkBladePermission($relation, $object, $connection)) {
                    return false;
                }
            }

            return true;
        });

        // @openfgauser directive - check if current user matches the given user identifier
        Blade::if('openfgauser', function (string $userId): bool {
            if (! Auth::check()) {
                return false;
            }

            $user = Auth::user();

            if (null === $user) {
                return false;
            }

            $provider = $this->app->make(BladeServiceProvider::class);
            $currentUserId = $provider->resolveUserId($user);

            return $currentUserId === $userId;
        });

        // @openfgaguest directive - check if user is not authenticated
        Blade::if('openfgaguest', static fn (): bool => ! Auth::check());

        // @openfgajs directive - generate JavaScript helpers
        Blade::directive('openfgajs', static function (?string $expression): string {
            // $expression comes from Blade compiler and is always a string or null
            if (null === $expression || '' === $expression) {
                return '<?php echo app(' . JavaScriptHelper::class . '::class)->bladeDirective(null); ?>';
            }

            return '<?php echo app(' . JavaScriptHelper::class . '::class)->bladeDirective(' . $expression . '); ?>';
        });

        // For backwards compatibility with Laravel's built-in @can directive
        // Override Laravel's @can to support OpenFGA when object contains ':'
        /** @var callable|null $originalCanDirective */
        $originalCanDirective = Blade::getCustomDirectives()['can'] ?? null;

        Blade::if('can', function ($ability, $arguments = null) use ($originalCanDirective) {
            if (! is_string($ability)) {
                return false;
            }

            // If it looks like an OpenFGA permission check (object contains ':')
            if (is_string($arguments) && str_contains($arguments, ':')) {
                $provider = $this->app->make(BladeServiceProvider::class);

                return $provider->checkBladePermission($ability, $arguments);
            }

            // Fall back to Laravel's original @can behavior
            if (null !== $originalCanDirective && is_callable($originalCanDirective)) {
                return $originalCanDirective($ability, $arguments);
            }

            // Default Laravel @can behavior
            $user = Auth::user();

            // Check if user has can method (e.g., User model with CanResetPassword trait)
            return Auth::check() && null !== $user && method_exists($user, 'can') && $user->can($ability, $arguments);
        });
    }

    /**
     * Register view composer for permission data.
     */
    private function registerViewComposer(): void
    {
        // Register a view composer that makes OpenFGA helpers available in all views
        /** @var Factory $viewFactory */
        $viewFactory = view();
        $viewFactory->composer('*', function ($view): void {
            /** @var View $view */
            $view->with('openfga', new class {
                /**
                 * @param string      $relation
                 * @param mixed       $object
                 * @param string|null $connection
                 */
                public function can(string $relation, $object, ?string $connection = null): bool
                {
                    if (! Auth::check()) {
                        return false;
                    }

                    $provider = app(BladeServiceProvider::class);

                    return $provider->checkBladePermission($relation, $object, $connection);
                }

                /**
                 * @param string      $relation
                 * @param mixed       $object
                 * @param string|null $connection
                 */
                public function cannot(string $relation, $object, ?string $connection = null): bool
                {
                    return ! $this->can($relation, $object, $connection);
                }

                /**
                 * @param array<int, string> $relations
                 * @param mixed              $object
                 * @param string|null        $connection
                 */
                public function canAny(array $relations, $object, ?string $connection = null): bool
                {
                    foreach ($relations as $relation) {
                        if (is_string($relation) && $this->can($relation, $object, $connection)) {
                            return true;
                        }
                    }

                    return false;
                }

                /**
                 * @param array<int, string> $relations
                 * @param mixed              $object
                 * @param string|null        $connection
                 */
                public function canAll(array $relations, $object, ?string $connection = null): bool
                {
                    foreach ($relations as $relation) {
                        if (! is_string($relation) || ! $this->can($relation, $object, $connection)) {
                            return false;
                        }
                    }

                    return true;
                }

                public function user(): ?string
                {
                    if (! Auth::check()) {
                        return null;
                    }

                    $provider = app(BladeServiceProvider::class);

                    $user = Auth::user();

                    if (null === $user) {
                        return null;
                    }

                    return $provider->resolveUserId($user);
                }
            });
        });
    }
}
