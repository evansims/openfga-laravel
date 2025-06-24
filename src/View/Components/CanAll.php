<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View\Components;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use OpenFGA\Laravel\OpenFgaManager;
use OpenFGA\Results\SuccessInterface;

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
            $result = $manager->connection($this->connection)->check($userId, $relation, $objectId);

            if (! $result instanceof SuccessInterface) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the view / contents that represent the component.
     */
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
     */
    private function resolveObject($object): string
    {
        return openfga_resolve_object($object);
    }

    /**
     * Resolve a user from identifier.
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
