<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use LogicException;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Laravel\Traits\{ResolvesAuthorizationObject, ResolvesAuthorizationUser};
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\{HttpException, NotFoundHttpException};

use function is_string;
use function sprintf;
use function strlen;

/**
 * Middleware that requires any of multiple permissions.
 *
 * @api
 */
final readonly class RequiresAnyPermission
{
    use ResolvesAuthorizationObject;

    use ResolvesAuthorizationUser;

    public function __construct(
        private OpenFgaManager $manager,
    ) {
    }

    /**
     * Handle an incoming request requiring any of the specified permissions.
     *
     * @param Request                      $request
     * @param Closure(Request): (Response) $next
     * @param string                       ...$relations Multiple relations, where any one grants access
     *
     * @throws HttpException
     * @throws HttpResponseException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws NotFoundHttpException
     */
    public function handle(Request $request, Closure $next, string ...$relations): Response
    {
        // Ensure user is authenticated
        if (! Auth::check()) {
            abort(401, 'Authentication required');
        }

        if ([] === $relations) {
            throw new InvalidArgumentException('At least one relation must be specified');
        }

        $user = $this->resolveUser($request);
        $object = $this->resolveObject($request);

        // Check if user has any of the required permissions
        foreach ($relations as $relation) {
            if ($this->manager->check($user, $relation, $object)) {
                return $next($request);
            }
        }

        $relationsList = implode(', ', $relations);
        abort(403, sprintf('Insufficient permissions. Required any of: %s on %s', $relationsList, $object));
    }

    /**
     * Infer object type from parameter name.
     *
     * @param string $parameterName
     */
    private function inferObjectType(string $parameterName): string
    {
        // Check for _id suffix
        if (str_ends_with($parameterName, '_id')) {
            return strtolower(substr($parameterName, 0, -3));
        }

        // Check for Id suffix
        if (str_ends_with($parameterName, 'Id') && 2 < strlen($parameterName)) {
            return strtolower(substr($parameterName, 0, -2));
        }

        // Default: use the parameter name as-is
        return strtolower($parameterName);
    }

    /**
     * Resolve the object identifier from route parameters.
     *
     * @param Request $request
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    private function resolveObject(Request $request): string
    {
        $route = $request->route();

        if (null === $route) {
            throw new InvalidArgumentException('No route found for object resolution');
        }

        // Look for Eloquent models in route parameters
        /** @var array<string, mixed> $parameters */
        $parameters = $route->parameters();

        /** @var mixed $value */
        foreach ($parameters as $value) {
            if ($value instanceof Model) {
                return $this->getAuthorizationObjectFromModel($value);
            }
        }

        // Look for string/numeric parameters that might represent objects
        /** @var mixed $value */
        foreach ($parameters as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                // Try to infer object type from parameter name
                $objectType = $this->inferObjectType($key);

                return $objectType . ':' . (string) $value;
            }
        }

        throw new InvalidArgumentException('Could not resolve authorization object from route parameters. Consider passing an explicit object parameter or implementing authorizationObject() on your models.');
    }

    /**
     * Resolve the user identifier from the request.
     *
     * @param Request $request
     *
     * @throws InvalidArgumentException If no authenticated user found
     *
     * @return string User identifier
     */
    private function resolveUser(Request $request): string
    {
        /** @var Authenticatable|null $user */
        $user = $request->user();

        if (null === $user) {
            throw new InvalidArgumentException('No authenticated user found');
        }

        return $this->resolveUserIdentifier($user);
    }
}
