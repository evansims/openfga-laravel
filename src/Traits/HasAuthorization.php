<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Traits;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenFGA\Exceptions\ClientThrowable;
use OpenFGA\Laravel\OpenFgaManager;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Provides authorization methods for Eloquent models.
 *
 * @mixin Model
 */
trait HasAuthorization
{
    /**
     * Get the authorization object string for this model.
     *
     * @return string The object identifier (e.g., "document:123")
     */
    public function authorizationObject(): string
    {
        return $this->authorizationType() . ':' . $this->getKey();
    }

    /**
     * Get the authorization type for this model.
     * Override this method to customize the type name.
     *
     * @return string The type name (e.g., "document")
     */
    public function authorizationType(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * Check if the current authenticated user has a specific permission.
     *
     * @param string $relation The relation/permission to check
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function can(string $relation): bool
    {
        return $this->check('@me', $relation);
    }

    /**
     * Check if a user has a specific permission.
     *
     * @param int|Model|string $user     The user to check
     * @param string           $relation The relation/permission to check
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function check($user, string $relation): bool
    {
        $userId = $this->resolveUserId($user);

        return $this->getOpenFgaManager()->check(
            $userId,
            $relation,
            $this->authorizationObject(),
        );
    }

    /**
     * Get the available relations for this model.
     * Override this method to define custom relations.
     *
     * @return array<string>
     */
    public function getAuthorizationRelations(): array
    {
        return ['owner', 'editor', 'viewer'];
    }

    /**
     * Get all relations a user has with this model.
     *
     * @param int|Model|string $user      The user to check
     * @param array<string>    $relations Optional relation filters
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *
     * @return array<string, bool>
     */
    public function getUserRelations($user, array $relations = []): array
    {
        $userId = $this->resolveUserId($user);

        if ([] === $relations) {
            $relations = $this->getAuthorizationRelations();
        }

        return $this->getOpenFgaManager()->listRelations(
            $userId,
            $this->authorizationObject(),
            $relations,
        );
    }

    /**
     * Get all users who have a specific relation with this model.
     *
     * @param string        $relation  The relation to check
     * @param array<string> $userTypes Optional user type filters
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return array<mixed>
     */
    public function getUsersWithRelation(string $relation, array $userTypes = []): array
    {
        return $this->getOpenFgaManager()->listUsers(
            $this->authorizationObject(),
            $relation,
            $userTypes,
        );
    }

    /**
     * Grant a permission to a user.
     *
     * @param int|Model|string $user     The user to grant permission to
     * @param string           $relation The relation/permission to grant
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function grant($user, string $relation): bool
    {
        $userId = $this->resolveUserId($user);

        return $this->getOpenFgaManager()->grant(
            $userId,
            $relation,
            $this->authorizationObject(),
        );
    }

    /**
     * Grant multiple permissions to multiple users.
     *
     * @param array<int|Model|string> $users     The users to grant permissions to
     * @param array<string>|string    $relations The relations/permissions to grant
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function grantMany(array $users, array | string $relations): bool
    {
        $relations = is_array($relations) ? $relations : [$relations];
        $tuples = [];

        foreach ($users as $user) {
            $userId = $this->resolveUserId($user);

            foreach ($relations as $relation) {
                $tuples[] = [
                    'user' => $userId,
                    'relation' => $relation,
                    'object' => $this->authorizationObject(),
                ];
            }
        }

        return $this->getOpenFgaManager()->query()->grant($tuples);
    }

    /**
     * Boot the trait and set up model events.
     */
    public function initializeHasAuthorization(): void
    {
        // Add model event to clean up permissions on delete
        static::deleted(function (Model $model): void {
            if ($this->shouldCleanupPermissionsOnDelete()) {
                $model->revokeAllPermissions();
            }
        });

        // Add model event to replicate permissions on duplication
        static::replicated(function (Model $original, Model $replica): void {
            if ($this->shouldReplicatePermissions()) {
                $original->replicatePermissionsTo($replica);
            }
        });
    }

    /**
     * Replicate permissions from this model to another.
     *
     * @param Model $target The target model to replicate permissions to
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function replicatePermissionsTo(Model $target): bool
    {
        if (! in_array(HasAuthorization::class, class_uses_recursive($target), true)) {
            throw new InvalidArgumentException('Target model must use HasAuthorization trait');
        }

        $relations = $this->getAuthorizationRelations();
        $success = true;

        foreach ($relations as $relation) {
            $users = $this->getUsersWithRelation($relation);

            if (! empty($users)) {
                foreach ($users as $user) {
                    $success = $target->grant($user, $relation) && $success;
                }
            }
        }

        return $success;
    }

    /**
     * Revoke a permission from a user.
     *
     * @param int|Model|string $user     The user to revoke permission from
     * @param string           $relation The relation/permission to revoke
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function revoke($user, string $relation): bool
    {
        $userId = $this->resolveUserId($user);

        return $this->getOpenFgaManager()->revoke(
            $userId,
            $relation,
            $this->authorizationObject(),
        );
    }

    /**
     * Revoke all permissions for this model.
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function revokeAllPermissions(): bool
    {
        // This is a simplified implementation
        // In a real scenario, you might want to query all existing tuples first
        $relations = $this->getAuthorizationRelations();
        $success = true;

        foreach ($relations as $relation) {
            $users = $this->getUsersWithRelation($relation);

            if (! empty($users)) {
                $revokeData = [];

                foreach ($users as $user) {
                    $revokeData[] = [
                        'user' => $user,
                        'relation' => $relation,
                        'object' => $this->authorizationObject(),
                    ];
                }

                if ([] !== $revokeData) {
                    $success = $this->getOpenFgaManager()->query()->revoke($revokeData) && $success;
                }
            }
        }

        return $success;
    }

    /**
     * Revoke multiple permissions from multiple users.
     *
     * @param array<int|Model|string> $users     The users to revoke permissions from
     * @param array<string>|string    $relations The relations/permissions to revoke
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function revokeMany(array $users, array | string $relations): bool
    {
        $relations = is_array($relations) ? $relations : [$relations];
        $tuples = [];

        foreach ($users as $user) {
            $userId = $this->resolveUserId($user);

            foreach ($relations as $relation) {
                $tuples[] = [
                    'user' => $userId,
                    'relation' => $relation,
                    'object' => $this->authorizationObject(),
                ];
            }
        }

        return $this->getOpenFgaManager()->query()->revoke($tuples);
    }

    /**
     * Add a scope to query models the current user has access to.
     *
     * @param Builder $query    The query builder
     * @param string  $relation The relation to check
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return Builder
     */
    public function scopeWhereCurrentUserCan($query, string $relation)
    {
        return $this->scopeWhereUserCan($query, '@me', $relation);
    }

    /**
     * Add a scope to query models the user has access to.
     *
     * @param Builder          $query    The query builder
     * @param int|Model|string $user     The user to check
     * @param string           $relation The relation to check
     *
     * @throws BindingResolutionException
     * @throws ClientThrowable
     * @throws Exception
     * @throws InvalidArgumentException
     *
     * @return Builder
     */
    public function scopeWhereUserCan($query, $user, string $relation)
    {
        $userId = $this->resolveUserId($user);

        $objects = $this->getOpenFgaManager()->listObjects(
            $userId,
            $relation,
            $this->authorizationType(),
        );

        // Extract IDs from the object strings (e.g., "document:123" -> "123")
        $ids = collect($objects)->map(static fn ($object) => Str::after($object, ':'))->filter()->values()->all();

        return $query->whereIn($this->getKeyName(), $ids);
    }

    /**
     * Get the OpenFGA manager instance.
     *
     * @throws BindingResolutionException
     */
    protected function getOpenFgaManager(): OpenFgaManager
    {
        return App::make(OpenFgaManager::class);
    }

    /**
     * Resolve a user identifier from various input types.
     *
     * @param int|Model|string $user
     *
     * @throws InvalidArgumentException
     *
     * @return string The resolved user identifier
     */
    protected function resolveUserId($user): string
    {
        if ($user instanceof Model) {
            // If it's a model with the trait, use its authorization object
            if (in_array(HasAuthorization::class, class_uses_recursive($user), true)) {
                return $user->authorizationObject();
            }

            // Otherwise, use the model type and key
            return Str::snake(class_basename($user)) . ':' . $user->getKey();
        }

        if (is_string($user)) {
            return $user;
        }

        if (is_int($user)) {
            return 'user:' . $user;
        }

        throw new InvalidArgumentException('User must be a Model, string, or integer');
    }

    /**
     * Check if permissions should be cleaned up on model deletion.
     * Override this method to customize behavior.
     */
    protected function shouldCleanupPermissionsOnDelete(): bool
    {
        return config('openfga.cleanup_on_delete', true);
    }

    /**
     * Check if permissions should be replicated when model is duplicated.
     * Override this method to customize behavior.
     */
    protected function shouldReplicatePermissions(): bool
    {
        return config('openfga.replicate_permissions', false);
    }
}
