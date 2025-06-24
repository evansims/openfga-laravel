<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that requires a specific permission.
 * This is an alias for OpenFgaMiddleware for better naming consistency.
 */
final readonly class RequiresPermission
{
    public function __construct(
        private OpenFgaMiddleware $middleware,
    ) {
    }

    /**
     * Handle an incoming request with simplified permission checking.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       $relation   The required relation/permission
     * @param string|null                  $object     The object to check permissions against (optional)
     * @param string|null                  $connection The OpenFGA connection to use (optional)
     */
    public function handle(Request $request, Closure $next, string $relation, ?string $object = null, ?string $connection = null): Response
    {
        return $this->middleware->handle($request, $next, $relation, $object, $connection);
    }
}
