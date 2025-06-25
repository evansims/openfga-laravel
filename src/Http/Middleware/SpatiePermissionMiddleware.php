<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\{Request, Response};
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function is_object;

/**
 * Spatie-compatible permission middleware.
 *
 * This middleware provides the same interface as Spatie's PermissionMiddleware
 * but uses OpenFGA for authorization checks.
 */
final class SpatiePermissionMiddleware
{
    public function __construct(private readonly SpatieCompatibility $compatibility)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string  $permission
     * @param ?string $guard
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $guard = null): SymfonyResponse
    {
        $user = auth($guard)->user();

        if (! $user) {
            return $this->unauthorized($request);
        }

        $permissions = explode('|', $permission);
        $model = $this->resolveModelFromRoute($request);

        if (! $this->compatibility->hasAnyPermission($user, $permissions, $model)) {
            return $this->unauthorized($request);
        }

        return $next($request);
    }

    /**
     * Resolve model from route parameters for contextual permissions.
     *
     * @param Request $request
     */
    private function resolveModelFromRoute(Request $request): ?object
    {
        $route = $request->route();

        if (! $route) {
            return null;
        }

        foreach ($route->parameters() as $parameter) {
            if (is_object($parameter) && method_exists($parameter, 'authorizationObject')) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Handle unauthorized access.
     *
     * @param Request $request
     */
    private function unauthorized(Request $request): SymfonyResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, 'You do not have the required permission.');
    }
}
