<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

use Illuminate\Contracts\Auth\Access\Gate as LaravelGateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;
use UnitEnum;

/**
 * OpenFGA Gate interface that extends Laravel's Gate contract.
 */
interface OpenFgaGateInterface extends LaravelGateContract
{
    /**
     * Determine if all of the given abilities should be granted for the current user.
     *
     * Supports both Laravel's native abilities and OpenFGA permission checks.
     * OpenFGA checks are identified by the presence of authorization objects in arguments.
     *
     * @param  iterable<mixed>|string|UnitEnum $abilities Single ability string or iterable of abilities
     * @param  array<mixed>|mixed              $arguments Authorization object(s), model instances, or traditional gate arguments
     * @param  Authenticatable|null            $user      User to check permissions for (optional, uses current user if null)
     * @return bool                            True if all abilities are granted, false otherwise
     */
    #[Override]
    public function check($abilities, $arguments = [], $user = null): bool;

    /**
     * Check a specific OpenFGA permission.
     *
     * @param  string                     $ability   The OpenFGA relation/permission to check
     * @param  array<mixed>|object|string $arguments Object identifier, model instance, or arguments array
     * @param  Authenticatable|null       $user      User to check permissions for
     * @return bool                       True if permission is granted, false otherwise
     */
    public function checkOpenFgaPermission(string $ability, mixed $arguments, ?Authenticatable $user = null): bool;

    /**
     * Determine if arguments represent an OpenFGA permission check.
     *
     * @param  mixed $arguments Arguments to analyze
     * @return bool  True if this appears to be an OpenFGA check
     */
    public function isOpenFgaPermission(mixed $arguments): bool;
}
