<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that requires a specific permission.
 * Extends the base OpenFgaMiddleware with a simpler interface.
 */
class RequiresPermission extends OpenFgaMiddleware
{
    /**
     * Handle an incoming request with simplified permission checking.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       $relation The required relation/permission
     * @param string|null                  $object   The object to check permissions against (optional)
     * 
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $relation, ?string $object = null): Response
    {
        return parent::handle($request, $next, $relation, $object);
    }
}