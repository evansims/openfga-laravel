<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Traits\{ResolvesAuthorizationObject, ResolvesAuthorizationUser};
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for batch loading permissions to optimize multiple checks.
 */
final readonly class LoadPermissions
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
     * Handle an incoming request by pre-loading permissions.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       ...$relations Relations to pre-load
     */
    public function handle(Request $request, Closure $next, string ...$relations): Response
    {
        if (! Auth::check() || [] === $relations) {
            return $next($request);
        }

        $user = $this->resolveUser($request);
        $objects = $this->resolveObjects($request);

        if ([] === $objects) {
            return $next($request);
        }

        // Pre-load permissions for all combinations
        $this->preloadPermissions($user, $relations, $objects);

        return $next($request);
    }

    /**
     * Pre-load permissions for the given user, relations, and objects.
     *
     * @param string        $user
     * @param array<string> $relations
     * @param array<string> $objects
     */
    private function preloadPermissions(string $user, array $relations, array $objects): void
    {
        // Use the manager's batch check functionality
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
    }

    /**
     * Resolve objects from the request context.
     *
     * @param  Request       $request
     * @return array<string>
     */
    private function resolveObjects(Request $request): array
    {
        /** @var array<string> $objects */
        $objects = [];
        $route = $request->route();

        if (null === $route) {
            return $objects;
        }

        // Extract objects from route parameters
        foreach ($route->parameters() as $value) {
            if ($value instanceof Model) {
                $objects[] = $this->getAuthorizationObjectFromModel($value);
            }
        }

        return array_unique($objects);
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
            return '';
        }

        return $this->resolveUserIdentifier($user);
    }
}
