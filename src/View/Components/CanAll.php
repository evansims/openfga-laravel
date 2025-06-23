<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Blade component for rendering content when user has all of the given OpenFGA permissions.
 */
class CanAll extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public array $relations,
        public mixed $object,
        public ?string $connection = null,
        public ?string $user = null
    ) {}

    /**
     * Determine if the user has all of the required permissions.
     *
     * @return bool
     */
    public function hasAllPermissions(): bool
    {
        $currentUser = $this->user ? $this->resolveUser($this->user) : Auth::user();
        
        if (!$currentUser) {
            return false;
        }

        $manager = app(OpenFgaManager::class);
        $userId = $this->resolveUserId($currentUser);
        $objectId = $this->resolveObject($this->object);

        foreach ($this->relations as $relation) {
            if (!$manager->connection($this->connection)->check($userId, $relation, $objectId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\Contracts\View\View|string
     */
    public function render(): View|string
    {
        if (!$this->hasAllPermissions()) {
            return '';
        }

        return view('openfga::components.can-all');
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
        if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
            return $user;
        }

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