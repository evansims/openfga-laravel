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
 * Blade component for rendering content when user does NOT have OpenFGA permissions.
 */
final class Cannot extends Component
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

        $result = $manager->connection($this->connection)->check($userId, $this->relation, $objectId);

        return $result instanceof SuccessInterface;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View | string
    {
        if ($this->hasPermission()) {
            return '';
        }

        return view('openfga::components.cannot');
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
