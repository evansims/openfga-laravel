<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

/**
 * Interface for models that can provide a custom authorization type.
 *
 * @api
 */
interface AuthorizationType
{
    /**
     * Get the authorization type for this model.
     */
    public function authorizationType(): string;
}
