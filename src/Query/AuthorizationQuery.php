<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Query;

use OpenFGA\Laravel\Abstracts\AbstractAuthorizationQuery;
use OpenFGA\Laravel\OpenFgaManager;
use Override;

/**
 * Fluent query builder for constructing OpenFGA authorization queries.
 *
 * This builder provides an expressive, chainable API for checking permissions,
 * granting/revoking access, and querying relationships. It follows Laravel's
 * query builder pattern, making authorization code readable and intuitive.
 * Supports batch operations, contextual tuples, and all OpenFGA query types
 * while maintaining type safety and validation.
 *
 * Example: $allowed = OpenFga::query()->for('user:123')->can('read')->on('document:456')->check();
 *
 * @api
 */
final class AuthorizationQuery extends AbstractAuthorizationQuery
{
    /**
     * Create a new authorization query instance.
     *
     * @param OpenFgaManager $manager
     * @param ?string        $connection
     */
    public function __construct(
        OpenFgaManager $manager,
        ?string $connection = null,
    ) {
        parent::__construct($manager, $connection);
    }

    /**
     * Clone the query with a clean state.
     */
    #[Override]
    public function fresh(): static
    {
        /** @var OpenFgaManager $manager */
        $manager = $this->manager;

        return new self($manager, $this->connection);
    }
}
