<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Helpers;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

use function gettype;
use function is_int;
use function is_string;

/**
 * Helper utility for handling Eloquent model keys with strict type checking.
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
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Model key must be int or string, got: ' . gettype($key));
        }

        return (string) $key;
    }
}
