<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\{Request, Response};
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Spatie-compatible role middleware.
 *
 * This middleware provides the same interface as Spatie's RoleMiddleware
 * but uses OpenFGA for authorization checks.
 */
final readonly class SpatieRoleMiddleware
{
    public function __construct(private SpatieCompatibility $compatibility)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string  $role
     * @param ?string $guard
     * @param ?string $context
     */
    public function handle(Request $request, Closure $next, string $role, ?string $guard = null, ?string $context = null): SymfonyResponse
    {
        $user = auth($guard)->user();

        if (! $user) {
            return $this->unauthorized($request);
        }

        $roles = explode('|', $role);

        if (! $this->compatibility->hasAnyRole($user, $roles, $context)) {
            return $this->unauthorized($request);
        }

        return $next($request);
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

        abort(Response::HTTP_FORBIDDEN, 'You do not have the required role.');
    }
}
