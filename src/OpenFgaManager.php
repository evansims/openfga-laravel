<?php

declare(strict_types=1);

namespace OpenFGA\Laravel;

use OpenFGA\Laravel\Abstracts\AbstractOpenFgaManager;
use OpenFGA\Laravel\Query\AuthorizationQuery;
use Override;

/**
 * Central manager for OpenFGA operations with multi-connection support.
 *
 * This class orchestrates all OpenFGA interactions, providing connection pooling,
 * caching, batch operations, and a fluent API. It supports multiple OpenFGA
 * connections with different configurations, automatic retry logic, and
 * comprehensive error handling through both Result pattern and exceptions.
 * Use this manager to check permissions, manage authorization tuples, and
 * query relationship data efficiently.
 *
 * @api
 */
final class OpenFgaManager extends AbstractOpenFgaManager
{
    /**
     * Create a new query builder instance.
     *
     * @param string|null $connection Optional connection name
     */
    #[Override]
    public function query(?string $connection = null): AuthorizationQuery
    {
        return new AuthorizationQuery($this, $connection);
    }
}
