<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View\Components;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use InvalidArgumentException;
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Results\SuccessInterface;
use Override;

use function gettype;
use function is_object;
use function is_scalar;
use function is_string;

/**
 * Blade component for rendering content when user has all of the given OpenFGA permissions.
 */
final class CanAll extends Component
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
     * Determine if the user has all of the required permissions.
     */
    public function hasAllPermissions(): bool
    {
        $currentUser = null !== $this->user ? $this->resolveUser() : Auth::user();

        if (null === $currentUser) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $userId = $this->resolveUserId($currentUser);
        $objectId = $this->resolveObject($this->object);

        foreach ($this->relations as $relation) {
            $result = $manager->check($userId, $relation, $objectId, [], [], $this->connection);

            if (! $result instanceof SuccessInterface) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the view / contents that represent the component.
     */
    #[Override]
    public function render(): View | string
    {
        if (! $this->hasAllPermissions()) {
            return '';
        }

        return view('openfga::components.can-all');
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
        if (is_object($object) && method_exists($object, 'authorizationType') && method_exists($object, 'getKey')) {
            $type = $object->authorizationType();
            $key = ModelKeyHelper::stringId($object);

            if (null === $type || (! is_string($type) && ! is_numeric($type))) {
                throw new InvalidArgumentException('Authorization type must be string or numeric');
            }

            return (string) $type . ':' . $key;
        }

        // Eloquent model fallback
        if (is_object($object) && method_exists($object, 'getTable') && method_exists($object, 'getKey')) {
            $table = $object->getTable();
            $key = ModelKeyHelper::stringId($object);

            if (! is_string($table)) {
                throw new InvalidArgumentException('Table name must be string');
            }

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
     */
    private function resolveUserId(Authenticatable $user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        $identifier = $user->getAuthIdentifier();

        return 'user:' . (is_scalar($identifier) ? (string) $identifier : '');
    }
}
