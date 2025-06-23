<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Blade component for rendering content based on OpenFGA permissions.
 */
class Can extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public string $relation,
        public mixed $object,
        public ?string $connection = null,
        public ?string $user = null
    ) {}

    /**
     * Determine if the user has the required permission.
     *
     * @return bool
     */
    public function hasPermission(): bool
    {
        $currentUser = $this->user ? $this->resolveUser($this->user) : Auth::user();
        
        if (!$currentUser) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $userId = $this->resolveUserId($currentUser);
        $objectId = $this->resolveObject($this->object);

        return $manager->connection($this->connection)->check($userId, $this->relation, $objectId);
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\Contracts\View\View|string
     */
    public function render(): View|string
    {
        if (!$this->hasPermission()) {
            return '';
        }

        return view('openfga::components.can');
    }

    /**
     * Resolve a user from identifier.
     *
     * @param mixed $user
     *
     * @return mixed
     */
    protected function resolveUser($user)
    {
        // If it's already a user object, return it
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            return $user;
        }

        // For now, just return the current authenticated user
        // In a real implementation, you might want to resolve users by ID
        return Auth::user();
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param mixed $user
     *
     * @return string
     */
    protected function resolveUserId($user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            return $user->getAuthorizationUserId();
        }

        return 'user:' . $user->getAuthIdentifier();
    }

    /**
     * Resolve the object identifier for OpenFGA.
     *
     * @param mixed $object
     *
     * @return string
     */
    protected function resolveObject($object): string
    {
        return openfga_resolve_object($object);
    }
}