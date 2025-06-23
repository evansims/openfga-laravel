<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that requires any of multiple permissions.
 */
class RequiresAnyPermission extends OpenFgaMiddleware
{
    /**
     * Handle an incoming request requiring any of the specified permissions.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       ...$relations Multiple relations, where any one grants access
     * 
     * @return Response
     */
    public function handle(Request $request, Closure $next, string ...$relations): Response
    {
        // Ensure user is authenticated
        if (!Auth::check()) {
            abort(401, 'Authentication required');
        }

        if (empty($relations)) {
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
        abort(403, "Insufficient permissions. Required any of: {$relationsList} on {$object}");
    }
}