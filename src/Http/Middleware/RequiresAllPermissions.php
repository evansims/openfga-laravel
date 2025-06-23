<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that requires all of multiple permissions.
 */
class RequiresAllPermissions extends OpenFgaMiddleware
{
    /**
     * Handle an incoming request requiring all of the specified permissions.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       ...$relations Multiple relations, where all are required
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

        // Check that user has all required permissions
        $missingPermissions = [];
        
        foreach ($relations as $relation) {
            if (!$this->manager->check($user, $relation, $object)) {
                $missingPermissions[] = $relation;
            }
        }

        if (!empty($missingPermissions)) {
            $missingList = implode(', ', $missingPermissions);
            abort(403, "Insufficient permissions. Missing: {$missingList} on {$object}");
        }

        return $next($request);
    }
}