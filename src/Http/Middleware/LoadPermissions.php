<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\OpenFgaManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for batch loading permissions to optimize multiple checks.
 */
class LoadPermissions
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected OpenFgaManager $manager
    ) {}

    /**
     * Handle an incoming request by pre-loading permissions.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       ...$relations Relations to pre-load
     * 
     * @return Response
     */
    public function handle(Request $request, Closure $next, string ...$relations): Response
    {
        if (!Auth::check() || empty($relations)) {
            return $next($request);
        }

        $user = $this->resolveUser($request);
        $objects = $this->resolveObjects($request);

        if (empty($objects)) {
            return $next($request);
        }

        // Pre-load permissions for all combinations
        $this->preloadPermissions($user, $relations, $objects);

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
            return '';
        }

        if (method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        return 'user:' . $user->getAuthIdentifier();
    }

    /**
     * Resolve objects from the request context.
     *
     * @param Request $request
     * 
     * @return array<string>
     */
    protected function resolveObjects(Request $request): array
    {
        $objects = [];
        $route = $request->route();
        
        if (!$route) {
            return $objects;
        }

        // Extract objects from route parameters
        foreach ($route->parameters() as $value) {
            if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                if (method_exists($value, 'authorizationObject')) {
                    $objects[] = $value->authorizationObject();
                } elseif (method_exists($value, 'authorizationType')) {
                    $objects[] = $value->authorizationType() . ':' . $value->getKey();
                } else {
                    $objects[] = $value->getTable() . ':' . $value->getKey();
                }
            }
        }

        return array_unique($objects);
    }

    /**
     * Pre-load permissions for the given user, relations, and objects.
     *
     * @param string        $user
     * @param array<string> $relations
     * @param array<string> $objects
     */
    protected function preloadPermissions(string $user, array $relations, array $objects): void
    {
        // Use the manager's batch check functionality if available
        if (method_exists($this->manager, 'batchCheck')) {
            $checks = [];
            
            foreach ($relations as $relation) {
                foreach ($objects as $object) {
                    $checks[] = [
                        'user' => $user,
                        'relation' => $relation,
                        'object' => $object,
                    ];
                }
            }
            
            $this->manager->batchCheck($checks);
        } else {
            // Fall back to individual checks (which should be cached)
            foreach ($relations as $relation) {
                foreach ($objects as $object) {
                    $this->manager->check($user, $relation, $object);
                }
            }
        }
    }
}