<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View\Components;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use InvalidArgumentException;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Results\SuccessInterface;
use Override;

use function gettype;
use function is_object;
use function is_scalar;
use function is_string;

/**
 * Blade component for rendering content based on OpenFGA permissions.
 */
final class Can extends Component
{
    /**
     * Create a new component instance.
     *
     * @param string      $relation
     * @param mixed       $object
     * @param string|null $connection
     * @param string|null $user
     */
    public function __construct(
        public string $relation,
        public mixed $object,
        public ?string $connection = null,
        public ?string $user = null,
    ) {
    }

    /**
     * Determine if the user has the required permission.
     */
    public function hasPermission(): bool
    {
        $currentUser = null !== $this->user ? $this->resolveUser() : Auth::user();

        if (null === $currentUser) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $userId = $this->resolveUserId($currentUser);
        $objectId = $this->resolveObject($this->object);

        $result = $manager->check($userId, $this->relation, $objectId, [], [], $this->connection);

        return $result instanceof SuccessInterface;
    }

    /**
     * Get the view / contents that represent the component.
     */
    #[Override]
    public function render(): View | string
    {
        if (! $this->hasPermission()) {
            return '';
        }

        return view('openfga::components.can');
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
            $key = $object->getKey();

            if (null === $type || (! is_string($type) && ! is_numeric($type))) {
                throw new InvalidArgumentException('Authorization type must be string or numeric');
            }

            if (null === $key || (! is_string($key) && ! is_numeric($key))) {
                throw new InvalidArgumentException('Model key must be string or numeric');
            }

            return (string) $type . ':' . (string) $key;
        }

        // Eloquent model fallback
        if (is_object($object) && method_exists($object, 'getTable') && method_exists($object, 'getKey')) {
            $table = $object->getTable();
            $key = $object->getKey();

            if (! is_string($table)) {
                throw new InvalidArgumentException('Table name must be string');
            }

            if (null === $key || (! is_string($key) && ! is_numeric($key))) {
                throw new InvalidArgumentException('Model key must be string or numeric');
            }

            return $table . ':' . (string) $key;
        }

        // Numeric ID - use 'resource' as default type
        if (is_numeric($object)) {
            return 'resource:' . (string) $object;
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($object));
    }

    /**
     * Resolve a user from identifier.
     */
    private function resolveUser(): ?Authenticatable
    {
        // For now, just return the current authenticated user
        // In a real implementation, you might want to resolve users by ID
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
            $result = $user->authorizationUser();

            return is_string($result) || is_numeric($result) ? (string) $result : 'user:unknown';
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            $result = $user->getAuthorizationUserId();

            return is_string($result) || is_numeric($result) ? (string) $result : 'user:unknown';
        }

        $identifier = $user->getAuthIdentifier();

        return 'user:' . (is_scalar($identifier) ? (string) $identifier : '');
    }
}
