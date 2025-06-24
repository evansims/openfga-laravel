<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Traits;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OpenFGA\Laravel\Helpers\ModelKeyHelper;

use function is_object;
use function is_string;

/**
 * Trait for resolving object identifiers for OpenFGA.
 */
trait ResolvesAuthorizationObject
{
    /**
     * Get authorization object string from a Model.
     *
     * @param Model $model
     *
     * @throws InvalidArgumentException
     */
    protected function getAuthorizationObjectFromModel(Model $model): string
    {
        // Use the model's authorization object method if available
        if (method_exists($model, 'authorizationObject')) {
            /** @var mixed $result */
            $result = $model->authorizationObject();

            return $this->toStringValue($result);
        }

        // Use the model's authorization type method if available
        if (method_exists($model, 'authorizationType')) {
            /** @var mixed $type */
            $type = $model->authorizationType();
            $key = ModelKeyHelper::stringId($model);

            return $this->toStringValue($type) . ':' . $key;
        }

        // Default to table name and key
        $table = $model->getTable();
        $key = ModelKeyHelper::stringId($model);

        return $this->toStringValue($table) . ':' . $key;
    }

    /**
     * Convert a value to string safely.
     *
     * @param mixed $value
     */
    protected function toStringValue($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }
}
