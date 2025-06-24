<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Traits\{ResolvesAuthorizationObject, ResolvesAuthorizationUser};
use Symfony\Component\HttpFoundation\Response;

use function is_string;
use function sprintf;

/**
 * Middleware that requires any of multiple permissions.
 */
final readonly class RequiresAnyPermission
{
    use ResolvesAuthorizationObject;

    use ResolvesAuthorizationUser;

    public function __construct(
        private OpenFgaManager $manager,
    ) {
    }

    /**
     * Handle an incoming request requiring any of the specified permissions.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       ...$relations Multiple relations, where any one grants access
     */
    public function handle(Request $request, Closure $next, string ...$relations): Response
    {
        // Ensure user is authenticated
        if (! Auth::check()) {
            abort(401, 'Authentication required');
        }

        if ([] === $relations) {
            throw new InvalidArgumentException('At least one relation must be specified');
        }

        $user = $this->resolveUser($request);
        $object = $this->resolveObject($request);

        // Check if user has any of the required permissions
        foreach ($relations as $relation) {
            if ($this->manager->check($user, $relation, $object)) {
                return $next($request);
            }
        }

        $relationsList = implode(', ', $relations);
        abort(403, sprintf('Insufficient permissions. Required any of: %s on %s', $relationsList, $object));
    }

    /**
     * Infer object type from parameter name.
     *
     * @param string $parameterName
     */
    private function inferObjectType(string $parameterName): ?string
    {
        // Common parameter patterns
        $patterns = [
            '/^(.+)_id$/' => '$1',           // user_id -> user
            '/^(.+)Id$/' => '$1',            // userId -> user
            '/^(.+)$/' => '$1',              // user -> user
        ];

        foreach (array_keys($patterns) as $pattern) {
            if (1 === preg_match($pattern, $parameterName, $matches)) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    /**
     * Resolve the object identifier from route parameters.
     *
     * @param Request $request
     *
     * @throws InvalidArgumentException
     */
    private function resolveObject(Request $request): string
    {
        $route = $request->route();

        if (null === $route) {
            throw new InvalidArgumentException('No route found for object resolution');
        }

        // Look for Eloquent models in route parameters
        foreach ($route->parameters() as $key => $value) {
            if ($value instanceof Model) {
                return $this->getAuthorizationObjectFromModel($value);
            }
        }

        // Look for string/numeric parameters that might represent objects
        foreach ($route->parameters() as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                // Try to infer object type from parameter name
                $objectType = $this->inferObjectType($key);

                if (null !== $objectType) {
                    return $objectType . ':' . $value;
                }
            }
        }

        throw new InvalidArgumentException('Could not resolve authorization object from route parameters. Consider passing an explicit object parameter or implementing authorizationObject() on your models.');
    }

    /**
     * Resolve the user identifier from the request.
     *
     * @param Request $request
     */
    private function resolveUser(Request $request): string
    {
        $user = $request->user();

        if (null === $user) {
            throw new InvalidArgumentException('No authenticated user found');
        }

        return $this->resolveUserIdentifier($user);
    }
}
