<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\Contracts\{AuthorizationUser, AuthorizationUserId};
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;

use function is_int;
use function is_object;
use function is_string;

/**
 * Builder class for creating permission-based menus.
 */
final readonly class MenuBuilder
{
    /**
     * Menu items collection.
     *
     * @var Collection<int, array<string, mixed>>
     */
    private Collection $items;

    /**
     * Create a new menu builder instance.
     *
     * @param OpenFgaManager $manager
     * @param ?string        $connection
     */
    public function __construct(
        private OpenFgaManager $manager,
        private ?string $connection = null,
    ) {
        $this->items = collect();
    }

    /**
     * Create a new menu builder instance.
     *
     * @param string|null $connection
     */
    public static function make(?string $connection = null): static
    {
        return new self(app(OpenFgaManager::class), $connection);
    }

    /**
     * Add a menu item with permission check.
     *
     * @param  string               $label
     * @param  string               $url
     * @param  string|null          $relation
     * @param  mixed                $object
     * @param  array<string, mixed> $attributes
     * @return $this
     */
    public function add(string $label, string $url, ?string $relation = null, $object = null, array $attributes = []): self
    {
        $this->items->push([
            'label' => $label,
            'url' => $url,
            'relation' => $relation,
            'object' => $object,
            'attributes' => $attributes,
            'children' => collect(),
        ]);

        return $this;
    }

    /**
     * Add a menu item that requires a specific permission.
     *
     * @param  string               $label
     * @param  string               $url
     * @param  string               $relation
     * @param  mixed                $object
     * @param  array<string, mixed> $attributes
     * @return $this
     */
    public function addIfCan(string $label, string $url, string $relation, $object, array $attributes = []): self
    {
        return $this->add($label, $url, $relation, $object, $attributes);
    }

    /**
     * Build the menu and filter items based on permissions.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function build(): Collection
    {
        return $this->filterItems($this->items);
    }

    /**
     * Add a divider.
     *
     * @return $this
     */
    public function divider(): self
    {
        $this->items->push([
            'type' => 'divider',
            'label' => '',
            'url' => null,
            'relation' => null,
            'object' => null,
            'attributes' => [],
            'children' => collect(),
        ]);

        return $this;
    }

    /**
     * Render the menu as HTML.
     *
     * @param string               $view
     * @param array<string, mixed> $data
     */
    public function render(string $view = 'openfga::menu', array $data = []): string
    {
        $items = $this->build();

        return view($view, array_merge($data, ['items' => $items]))->render();
    }

    /**
     * Add a submenu.
     *
     * @param  string               $label
     * @param  callable(self): void $callback
     * @param  string|null          $relation
     * @param  mixed                $object
     * @param  array<string, mixed> $attributes
     * @return $this
     */
    public function submenu(string $label, callable $callback, ?string $relation = null, $object = null, array $attributes = []): self
    {
        $submenu = new self($this->manager, $this->connection);
        $callback($submenu);

        $this->items->push([
            'label' => $label,
            'url' => null,
            'relation' => $relation,
            'object' => $object,
            'attributes' => $attributes,
            'children' => $submenu->items,
        ]);

        return $this;
    }

    /**
     * Build the menu as an array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        /** @var array<int, array<string, mixed>> */
        return $this->build()->toArray();
    }

    /**
     * Filter menu items based on permissions.
     *
     * @param  Collection<int, array<string, mixed>> $items
     * @return Collection<int, array<string, mixed>>
     */
    private function filterItems(Collection $items): Collection
    {
        /** @var Collection<int, array<string, mixed>> */
        return $items->filter(function (array $item): bool {
            // Always show dividers
            if (isset($item['type']) && 'divider' === $item['type']) {
                return true;
            }

            // Check permission if specified
            $relation = $item['relation'] ?? null;
            $object = $item['object'] ?? null;

            if (is_string($relation) && null !== $object && ! $this->hasPermission($relation, $object)) {
                return false;
            }

            // Filter children recursively
            $children = $item['children'] ?? null;

            if ($children instanceof Collection && $children->isNotEmpty()) {
                /** @var Collection<int, array<string, mixed>> $children */
                $item['children'] = $this->filterItems($children);

                // Hide parent if all children are hidden
                if ($item['children']->isEmpty()) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Check if the current user has the given permission.
     *
     * @param string $relation
     * @param mixed  $object
     */
    private function hasPermission(string $relation, $object): bool
    {
        if (! Auth::check()) {
            return false;
        }

        $user = Auth::user();
        $userId = $this->resolveUserId($user);
        $objectId = $this->resolveObject($object);

        return $this->manager->check($userId, $relation, $objectId, [], [], $this->connection);
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
     * Resolve the user ID for OpenFGA.
     *
     * @param Authenticatable|null $user
     */
    private function resolveUserId($user): string
    {
        if (null === $user) {
            throw new RuntimeException('User is null');
        }

        if (is_object($user) && method_exists($user, 'authorizationUser')) {
            /** @var Authenticatable&AuthorizationUser $user */
            return (string) $user->authorizationUser();
        }

        if (is_object($user) && method_exists($user, 'getAuthorizationUserId')) {
            /** @var Authenticatable&AuthorizationUserId $user */
            return (string) $user->getAuthorizationUserId();
        }

        $identifier = $user->getAuthIdentifier();

        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new RuntimeException('User identifier must be string or int');
        }

        return 'user:' . (string) $identifier;
    }
}
