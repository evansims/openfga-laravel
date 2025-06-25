<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use LogicException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Traits\{ResolvesAuthorizationObject, ResolvesAuthorizationUser};
use Symfony\Component\HttpFoundation\Response;

use function is_string;
use function sprintf;
use function strlen;

/**
 * Middleware for protecting routes with OpenFGA authorization.
 */
final class OpenFgaMiddleware
{
    use ResolvesAuthorizationObject;

    use ResolvesAuthorizationUser;

    /**
     * Create a new middleware instance.
     *
     * @param OpenFgaManager $manager
     */
    public function __construct(
        private OpenFgaManager $manager,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       $relation   The required relation/permission
     * @param string|null                  $object     The object to check permissions against (optional)
     * @param string|null                  $connection The OpenFGA connection to use (optional)
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, Closure $next, string $relation, ?string $object = null, ?string $connection = null): Response
    {
        // Ensure user is authenticated
        if (! Auth::check()) {
            abort(401, 'Authentication required');
        }

        $user = $this->resolveUser($request);
        $objectId = $object ?? $this->resolveObject($request);

        // Check permission
        $allowed = $this->manager->check($user, $relation, $objectId, [], [], $connection);

        if (! $allowed) {
            abort(403, sprintf('Insufficient permissions. Required: %s on %s', $relation, $objectId));
        }

        return $next($request);
    }

    /**
     * Infer object type from parameter name.
     *
     * @param string $parameterName
     */
    private function inferObjectType(string $parameterName): string
    {
        // Check for _id suffix
        if (str_ends_with($parameterName, '_id')) {
            return strtolower(substr($parameterName, 0, -3));
        }

        // Check for Id suffix
        if (str_ends_with($parameterName, 'Id') && 2 < strlen($parameterName)) {
            return strtolower(substr($parameterName, 0, -2));
        }

        // Default: use the parameter name as-is
        return strtolower($parameterName);
    }

    /**
     * Resolve the object identifier from route parameters.
     *
     * @param Request $request
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function resolveObject(Request $request): string
    {
        $route = $request->route();

        if (null === $route) {
            throw new InvalidArgumentException('No route found for object resolution');
        }

        // Look for Eloquent models in route parameters
        /** @var array<string, mixed> $parameters */
        $parameters = $route->parameters();

        /** @var mixed $value */
        foreach ($parameters as $value) {
            if ($value instanceof Model) {
                return $this->getAuthorizationObjectFromModel($value);
            }
        }

        // Look for string/numeric parameters that might represent objects
        /** @var mixed $value */
        foreach ($parameters as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                // Try to infer object type from parameter name
                $objectType = $this->inferObjectType($key);

                return $objectType . ':' . (string) $value;
            }
        }

        throw new InvalidArgumentException('Could not resolve authorization object from route parameters. Consider passing an explicit object parameter or implementing authorizationObject() on your models.');
    }

    /**
     * Resolve the user identifier from the request.
     *
     * @param Request $request
     *
     * @throws InvalidArgumentException If no authenticated user found
     *
     * @return string User identifier
     */
    private function resolveUser(Request $request): string
    {
        /** @var Authenticatable|null $user */
        $user = $request->user();

        if (null === $user) {
            throw new InvalidArgumentException('No authenticated user found');
        }

        return $this->resolveUserIdentifier($user);
    }
}
