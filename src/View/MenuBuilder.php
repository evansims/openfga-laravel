<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use OpenFGA\Laravel\OpenFgaManager;

/**
 * Builder class for creating permission-based menus.
 */
class MenuBuilder
{
    /**
     * Menu items collection.
     *
     * @var Collection<int, array>
     */
    protected Collection $items;

    /**
     * Create a new menu builder instance.
     */
    public function __construct(
        protected OpenFgaManager $manager,
        protected ?string $connection = null
    ) {
        $this->items = collect();
    }

    /**
     * Add a menu item with permission check.
     *
     * @param string      $label
     * @param string      $url
     * @param string|null $relation
     * @param mixed       $object
     * @param array<string, mixed> $attributes
     *
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
     * @param string      $label
     * @param string      $url
     * @param string      $relation
     * @param mixed       $object
     * @param array<string, mixed> $attributes
     *
     * @return $this
     */
    public function addIfCan(string $label, string $url, string $relation, $object, array $attributes = []): self
    {
        return $this->add($label, $url, $relation, $object, $attributes);
    }

    /**
     * Add a submenu.
     *
     * @param string   $label
     * @param callable $callback
     * @param string|null $relation
     * @param mixed    $object
     * @param array<string, mixed> $attributes
     *
     * @return $this
     */
    public function submenu(string $label, callable $callback, ?string $relation = null, $object = null, array $attributes = []): self
    {
        $submenu = new static($this->manager, $this->connection);
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
     * Build the menu and filter items based on permissions.
     *
     * @return Collection<int, array>
     */
    public function build(): Collection
    {
        return $this->filterItems($this->items);
    }

    /**
     * Build the menu as an array.
     *
     * @return array<int, array>
     */
    public function toArray(): array
    {
        return $this->build()->toArray();
    }

    /**
     * Render the menu as HTML.
     *
     * @param string $view
     * @param array<string, mixed> $data
     *
     * @return string
     */
    public function render(string $view = 'openfga::menu', array $data = []): string
    {
        $items = $this->build();
        
        return view($view, array_merge($data, ['items' => $items]))->render();
    }

    /**
     * Filter menu items based on permissions.
     *
     * @param Collection<int, array> $items
     *
     * @return Collection<int, array>
     */
    protected function filterItems(Collection $items): Collection
    {
        return $items->filter(function ($item) {
            // Always show dividers
            if (isset($item['type']) && $item['type'] === 'divider') {
                return true;
            }

            // Check permission if specified
            if ($item['relation'] && $item['object']) {
                if (!$this->hasPermission($item['relation'], $item['object'])) {
                    return false;
                }
            }

            // Filter children recursively
            if ($item['children'] && $item['children']->isNotEmpty()) {
                $item['children'] = $this->filterItems($item['children']);
                
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
     *
     * @return bool
     */
    protected function hasPermission(string $relation, $object): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $user = Auth::user();
        $userId = $this->resolveUserId($user);
        $objectId = $this->resolveObject($object);

        return $this->manager
            ->connection($this->connection)
            ->check($userId, $relation, $objectId);
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

    /**
     * Create a new menu builder instance.
     *
     * @param string|null $connection
     *
     * @return static
     */
    public static function make(?string $connection = null): static
    {
        return new static(app(OpenFgaManager::class), $connection);
    }
}