<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Contracts;

/**
 * Contract for models that represent authorization objects in OpenFGA.
 *
 * Implement this interface on any Eloquent model that needs custom object
 * identification in OpenFGA. This allows you to define how your models are
 * represented in authorization tuples (e.g., 'document:123' or 'project:uuid').
 * Without this interface, models default to using their table name and primary key.
 *
 * @api
 */
interface AuthorizationObject
{
    /**
     * Get the authorization object identifier in format "type:id".
     */
    public function authorizationObject(): string;
}
