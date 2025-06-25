<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Spatie-compatible role middleware
 * 
 * This middleware provides the same interface as Spatie's RoleMiddleware
 * but uses OpenFGA for authorization checks.
 */
class SpatieRoleMiddleware
{
    private SpatieCompatibility $compatibility;

    public function __construct(SpatieCompatibility $compatibility)
    {
        $this->compatibility = $compatibility;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $role, ?string $guard = null, ?string $context = null): SymfonyResponse
    {
        $user = auth($guard)->user();

        if (!$user) {
            return $this->unauthorized($request);
        }

        $roles = explode('|', $role);

        if (!$this->compatibility->hasAnyRole($user, $roles, $context)) {
            return $this->unauthorized($request);
        }

        return $next($request);
    }

    /**
     * Handle unauthorized access
     */
    private function unauthorized(Request $request): SymfonyResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, 'You do not have the required role.');
    }
}