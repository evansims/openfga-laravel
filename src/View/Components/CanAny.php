<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View\Components;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\{Component, ComponentAttributeBag};
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\AuthorizationType;
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;
use Override;

use function gettype;
use function is_object;
use function is_scalar;
use function is_string;

/**
 * Blade component for rendering content when user has any of the given OpenFGA permissions.
 *
 * @property string|null           $componentName
 * @property array<string>         $except
 * @property ComponentAttributeBag $attributes
 */
final class CanAny extends Component
{
    /**
     * Create a new component instance.
     *
     * @param array<string> $relations
     * @param mixed         $object
     * @param string|null   $connection
     * @param string|null   $user
     */
    public function __construct(
        public array $relations,
        public mixed $object,
        public ?string $connection = null,
        public ?string $user = null,
    ) {
    }

    /**
     * Determine if the user has any of the required permissions.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function hasAnyPermission(): bool
    {
        $currentUser = null !== $this->user ? $this->resolveUser() : Auth::user();

        if (null === $currentUser) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $userId = $this->resolveUserId($currentUser);
        $objectId = $this->resolveObject($this->object);

        foreach ($this->relations as $relation) {
            if ($manager->check($userId, $relation, $objectId, [], [], $this->connection)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Override]
    public function render(): View | string
    {
        if (! $this->hasAnyPermission()) {
            return '';
        }

        return view('openfga::components.can-any');
    }

    /**
     * Resolve the object identifier for OpenFGA.
     *
     * @param mixed $object
     *
     * @throws InvalidArgumentException
     */
    private function resolveObject($object): string
    {
        // String in object:id format
        if (is_string($object)) {
            return $object;
        }

        // Model with authorization support
        if (is_object($object) && method_exists($object, 'authorizationObject')) {
            /** @var mixed|string $result */
            $result = $object->authorizationObject();

            if (is_string($result)) {
                return $result;
            }

            if (is_scalar($result) || (is_object($result) && method_exists($result, '__toString'))) {
                return (string) $result;
            }

            throw new InvalidArgumentException('authorizationObject() must return a string or stringable value');
        }

        // Model with authorization type method
        if (is_object($object) && $object instanceof Model && $object instanceof AuthorizationType) {
            /** @var AuthorizationType&Model $object */
            $type = $object->authorizationType();
            $key = ModelKeyHelper::stringId($object);

            return $type . ':' . $key;
        }

        // Eloquent model fallback
        if (is_object($object) && $object instanceof Model) {
            $table = $object->getTable();
            $key = ModelKeyHelper::stringId($object);

            return $table . ':' . $key;
        }

        // Numeric ID - use 'resource' as default type
        if (is_numeric($object)) {
            return 'resource:' . (string) $object;
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($object));
    }

    /**
     * Resolve a user from identifier.
     *
     * @return Authenticatable|null The resolved user or null if not found
     */
    private function resolveUser(): ?Authenticatable
    {
        return Auth::user();
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param Authenticatable $user
     *
     * @throws InvalidArgumentException
     */
    private function resolveUserId(Authenticatable $user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            /** @var mixed|numeric|string $result */
            $result = $user->authorizationUser();

            if (is_string($result) || is_numeric($result)) {
                return (string) $result;
            }

            throw new InvalidArgumentException('authorizationUser() must return a string or numeric value');
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            /** @var mixed|numeric|string $result */
            $result = $user->getAuthorizationUserId();

            if (is_string($result) || is_numeric($result)) {
                return (string) $result;
            }

            throw new InvalidArgumentException('getAuthorizationUserId() must return a string or numeric value');
        }

        /** @var int|mixed|string $identifier */
        $identifier = $user->getAuthIdentifier();

        if (is_scalar($identifier)) {
            return 'user:' . (string) $identifier;
        }

        throw new InvalidArgumentException('User identifier must be scalar');
    }
}
