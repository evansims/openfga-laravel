<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\View;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\Contracts\{AuthorizationType, AuthorizationUser, AuthorizationUserId};
use OpenFGA\Laravel\Helpers\ModelKeyHelper;
use OpenFGA\Laravel\OpenFgaManager;
use RuntimeException;

use function gettype;
use function is_int;
use function is_object;
use function is_scalar;
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
    public function add(string $label, string $url, ?string $relation = null, mixed $object = null, array $attributes = []): self
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
    public function addIfCan(string $label, string $url, string $relation, mixed $object, array $attributes = []): self
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
    public function submenu(string $label, callable $callback, ?string $relation = null, mixed $object = null, array $attributes = []): self
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
            /** @var mixed $relation */
            $relation = $item['relation'] ?? null;

            /** @var mixed $object */
            $object = $item['object'] ?? null;

            if (is_string($relation) && null !== $object && ! $this->hasPermission($relation, $object)) {
                return false;
            }

            // Filter children recursively
            /** @var mixed $children */
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
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    private function hasPermission(string $relation, mixed $object): bool
    {
        if (! Auth::check()) {
            return false;
        }

        $user = Auth::user();

        if (null === $user) {
            return false;
        }

        $userId = $this->resolveUserId($user);
        $objectId = $this->resolveObject($object);

        return $this->manager->check($userId, $relation, $objectId, [], [], $this->connection);
    }

    /**
     * Resolve an object identifier for OpenFGA.
     *
     * @param mixed $object
     *
     * @throws InvalidArgumentException
     */
    private function resolveObject(mixed $object): string
    {
        // String in object:id format
        if (is_string($object)) {
            return $object;
        }

        // Model with authorization support
        if (is_object($object) && method_exists($object, 'authorizationObject')) {
            /** @var mixed $result */
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

        // Numeric ID - use 'menu-item' as default type
        if (is_numeric($object)) {
            return 'menu-item:' . (string) $object;
        }

        throw new InvalidArgumentException('Cannot resolve object identifier for: ' . gettype($object));
    }

    /**
     * Resolve the user ID for OpenFGA.
     *
     * @param Authenticatable $user The authenticated user
     *
     * @throws RuntimeException
     *
     * @return string The user identifier for OpenFGA
     */
    private function resolveUserId(Authenticatable $user): string
    {
        if (method_exists($user, 'authorizationUser')) {
            /** @var Authenticatable&AuthorizationUser $user */
            return $user->authorizationUser();
        }

        if (method_exists($user, 'getAuthorizationUserId')) {
            /** @var Authenticatable&AuthorizationUserId $user */
            return $user->getAuthorizationUserId();
        }

        $identifier = $user->getAuthIdentifier();

        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new RuntimeException('User identifier must be string or int');
        }

        return 'user:' . (string) $identifier;
    }
}
