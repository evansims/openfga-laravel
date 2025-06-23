<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for protecting routes with OpenFGA authorization.
 */
class OpenFgaMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected OpenFgaManager $manager
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       $relation The required relation/permission
     * @param string|null                  $object   The object to check permissions against (optional)
     * @param string|null                  $connection The OpenFGA connection to use (optional)
     * 
     * @return Response
     * 
     * @throws \Illuminate\Auth\AuthenticationException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \InvalidArgumentException
     */
    public function handle(Request $request, Closure $next, string $relation, ?string $object = null, ?string $connection = null): Response
    {
        // Ensure user is authenticated
        if (!Auth::check()) {
            abort(401, 'Authentication required');
        }

        $user = $this->resolveUser($request);
        $objectId = $object ?? $this->resolveObject($request);

        // Check permission
        $allowed = $this->manager
            ->connection($connection)
            ->check($user, $relation, $objectId);

        if (!$allowed) {
            abort(403, "Insufficient permissions. Required: {$relation} on {$objectId}");
        }

        return $next($request);
    }

    /**
     * Resolve the user identifier from the request.
     *
     * @param Request $request
     * 
     * @return string
     */
    protected function resolveUser(Request $request): string
    {
        $user = $request->user();
        
        if (!$user) {
            throw new InvalidArgumentException('No authenticated user found');
        }

        // Support different user identifier methods
        if (method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        // Default to user:{id}
        return 'user:' . $user->getAuthIdentifier();
    }

    /**
     * Resolve the object identifier from route parameters.
     *
     * @param Request $request
     * 
     * @return string
     * 
     * @throws InvalidArgumentException
     */
    protected function resolveObject(Request $request): string
    {
        $route = $request->route();
        
        if (!$route) {
            throw new InvalidArgumentException('No route found for object resolution');
        }

        // Look for Eloquent models in route parameters
        foreach ($route->parameters() as $key => $value) {
            if ($value instanceof Model) {
                // Use the model's authorization object method if available
                if (method_exists($value, 'authorizationObject')) {
                    return $value->authorizationObject();
                }

                // Use the model's authorization type method if available
                if (method_exists($value, 'authorizationType')) {
                    return $value->authorizationType() . ':' . $value->getKey();
                }

                // Default to table name and key
                return $value->getTable() . ':' . $value->getKey();
            }
        }

        // Look for string/numeric parameters that might represent objects
        foreach ($route->parameters() as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                // Try to infer object type from parameter name
                $objectType = $this->inferObjectType($key);
                if ($objectType) {
                    return $objectType . ':' . $value;
                }
            }
        }

        throw new InvalidArgumentException(
            'Could not resolve authorization object from route parameters. ' .
            'Consider passing an explicit object parameter or implementing authorizationObject() on your models.'
        );
    }

    /**
     * Infer object type from parameter name.
     *
     * @param string $parameterName
     * 
     * @return string|null
     */
    protected function inferObjectType(string $parameterName): ?string
    {
        // Common parameter patterns
        $patterns = [
            '/^(.+)_id$/' => '$1',           // user_id -> user
            '/^(.+)Id$/' => '$1',            // userId -> user
            '/^(.+)$/' => '$1',              // user -> user
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $parameterName, $matches)) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }
}