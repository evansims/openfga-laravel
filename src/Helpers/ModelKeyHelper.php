<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Helpers;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OpenFGA\Laravel\Support\TypeValidator;

/**
 * Helper utility for handling Eloquent model keys with strict type checking.
 *
 * @internal
 */
final class ModelKeyHelper
{
    /**
     * Get a string representation of a model's key with strict type checking.
     *
     * @param Model $model The Eloquent model
     *
     * @throws InvalidArgumentException If the key is not int|string
     *
     * @return string The model key as a string
     */
    public static function stringId(Model $model): string
    {
        /** @var mixed $key */
        $key = $model->getKey();

        $validatedKey = TypeValidator::ensureIntOrString($key, 'model key');

        return (string) $validatedKey;
    }
}
