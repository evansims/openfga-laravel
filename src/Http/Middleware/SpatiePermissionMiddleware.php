<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\{Request, Response};
use LogicException;
use OpenFGA\Laravel\Compatibility\SpatieCompatibility;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\{HttpException, NotFoundHttpException};

/**
 * Spatie-compatible permission middleware.
 *
 * This middleware provides the same interface as Spatie's PermissionMiddleware
 * but uses OpenFGA for authorization checks.
 *
 * @api
 */
final readonly class SpatiePermissionMiddleware
{
    public function __construct(private SpatieCompatibility $compatibility)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request                           $request
     * @param Closure(Request): SymfonyResponse $next
     * @param string                            $permission
     * @param ?string                           $guard
     *
     * @throws HttpException|HttpResponseException|NotFoundHttpException
     * @throws LogicException
     */
    public function handle(Request $request, Closure $next, string $permission, ?string $guard = null): SymfonyResponse
    {
        $user = auth()->guard($guard)->user();

        if (null === $user) {
            return $this->unauthorized($request);
        }

        $permissions = explode('|', $permission);
        $model = $this->resolveModelFromRoute($request);

        if (! $user instanceof Model) {
            return $this->unauthorized($request);
        }

        if (! $this->compatibility->hasAnyPermission($user, $permissions, $model)) {
            return $this->unauthorized($request);
        }

        return $next($request);
    }

    /**
     * Resolve model from route parameters for contextual permissions.
     *
     * @param Request $request
     *
     * @throws LogicException
     */
    private function resolveModelFromRoute(Request $request): ?Model
    {
        $route = $request->route();

        if (null === $route) {
            return null;
        }

        /** @var mixed $parameter */
        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model && method_exists($parameter, 'authorizationObject')) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Handle unauthorized access.
     *
     * @param Request $request
     *
     * @throws HttpException|HttpResponseException|NotFoundHttpException
     */
    private function unauthorized(Request $request): SymfonyResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, 'You do not have the required permission.');
    }
}
