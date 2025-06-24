<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use OpenFGA\Exceptions\ClientThrowable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\{HttpException, NotFoundHttpException};

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
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws AuthenticationException
     * @throws AuthorizationException
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws HttpException
     * @throws HttpResponseException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws NotFoundHttpException
     */
    public function handle(Request $request, Closure $next, string $relation, ?string $object = null, ?string $connection = null): Response
    {
        return $this->middleware->handle($request, $next, $relation, $object, $connection);
    }
}
