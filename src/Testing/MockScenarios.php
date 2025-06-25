<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use Closure;

/**
 * Provides pre-configured mock scenarios for common testing situations.
 */
final readonly class MockScenarios
{
    public function __construct(private FakeOpenFga $fake)
    {
    }

    /**
     * Create a scenario builder.
     *
     * @param FakeOpenFga $fake
     */
    public static function create(FakeOpenFga $fake): self
    {
        return new self($fake);
    }

    /**
     * Set up an API access control scenario.
     */
    public function apiAccessControl(): self
    {
        $this->fake->reset();

        // API clients
        $this->fake->grant('client:mobile-app', 'read', 'api:users');
        $this->fake->grant('client:mobile-app', 'read', 'api:posts');

        $this->fake->grant('client:admin-dashboard', 'read', 'api:users');
        $this->fake->grant('client:admin-dashboard', 'write', 'api:users');
        $this->fake->grant('client:admin-dashboard', 'read', 'api:analytics');

        $this->fake->grant('client:third-party', 'read', 'api:public-data');

        // Rate limits (as relationships)
        $this->fake->grant('client:mobile-app', 'standard_limit', 'api:rate-limiter');
        $this->fake->grant('client:admin-dashboard', 'premium_limit', 'api:rate-limiter');
        $this->fake->grant('client:third-party', 'basic_limit', 'api:rate-limiter');

        return $this;
    }

    /**
     * Set up a basic user-document scenario.
     */
    public function basicUserDocument(): self
    {
        $this->fake->reset();

        // User 1 owns document 1
        $this->fake->grant('user:1', 'owner', 'document:1');

        // User 2 can edit document 1
        $this->fake->grant('user:2', 'editor', 'document:1');

        // User 3 can only view document 1
        $this->fake->grant('user:3', 'viewer', 'document:1');

        return $this;
    }

    /**
     * Set up a collaborative editing scenario.
     */
    public function collaborativeEditing(): self
    {
        $this->fake->reset();

        // Project setup
        $this->fake->grant('user:lead', 'owner', 'project:website');
        $this->fake->grant('team:frontend', 'collaborator', 'project:website');
        $this->fake->grant('team:backend', 'collaborator', 'project:website');

        // Team memberships
        $this->fake->grant('user:designer1', 'member', 'team:frontend');
        $this->fake->grant('user:designer2', 'member', 'team:frontend');
        $this->fake->grant('user:dev1', 'member', 'team:backend');
        $this->fake->grant('user:dev2', 'member', 'team:backend');

        // Documents in project
        $this->fake->grant('project:website', 'project', 'document:spec');
        $this->fake->grant('project:website', 'project', 'document:design');
        $this->fake->grant('project:website', 'project', 'document:api');

        return $this;
    }

    /**
     * Combine multiple scenarios.
     *
     * @param array $scenarios
     */
    public function combine(array $scenarios): self
    {
        foreach ($scenarios as $scenario) {
            if (method_exists($this, $scenario)) {
                $this->{$scenario}();
            }
        }

        return $this;
    }

    /**
     * Set up a content moderation scenario.
     */
    public function contentModeration(): self
    {
        $this->fake->reset();

        // Moderators
        $this->fake->grant('user:mod1', 'moderator', 'forum:general');
        $this->fake->grant('user:mod2', 'moderator', 'forum:tech');

        // Global moderator
        $this->fake->grant('user:admin', 'global_moderator', 'platform:main');
        $this->fake->grant('forum:general', 'forum', 'platform:main');
        $this->fake->grant('forum:tech', 'forum', 'platform:main');

        // Regular users and their posts
        $this->fake->grant('user:poster1', 'author', 'post:1');
        $this->fake->grant('user:poster2', 'author', 'post:2');
        $this->fake->grant('post:1', 'post', 'forum:general');
        $this->fake->grant('post:2', 'post', 'forum:tech');

        return $this;
    }

    /**
     * Add custom scenario.
     *
     * @param Closure $setup
     */
    public function custom(Closure $setup): self
    {
        $setup($this->fake);

        return $this;
    }

    /**
     * Set up a file system scenario.
     */
    public function fileSystem(): self
    {
        $this->fake->reset();

        // Folder structure
        $this->fake->grant('user:owner', 'owner', 'folder:root');
        $this->fake->grant('folder:documents', 'parent', 'folder:root');
        $this->fake->grant('folder:images', 'parent', 'folder:root');
        $this->fake->grant('folder:private', 'parent', 'folder:documents');

        // File permissions
        $this->fake->grant('file:report.pdf', 'parent', 'folder:documents');
        $this->fake->grant('file:photo.jpg', 'parent', 'folder:images');
        $this->fake->grant('file:secret.doc', 'parent', 'folder:private');

        // Share some files
        $this->fake->grant('user:collaborator', 'viewer', 'file:report.pdf');
        $this->fake->grant('user:designer', 'editor', 'folder:images');

        return $this;
    }

    /**
     * Get the configured fake instance.
     */
    public function getFake(): FakeOpenFga
    {
        return $this->fake;
    }

    /**
     * Set up a multi-tenant scenario.
     */
    public function multiTenant(): self
    {
        $this->fake->reset();

        // Tenant A
        $this->fake->grant('user:alice', 'admin', 'tenant:a');
        $this->fake->grant('user:bob', 'member', 'tenant:a');
        $this->fake->grant('resource:1', 'tenant', 'tenant:a');
        $this->fake->grant('resource:2', 'tenant', 'tenant:a');

        // Tenant B
        $this->fake->grant('user:charlie', 'admin', 'tenant:b');
        $this->fake->grant('user:david', 'member', 'tenant:b');
        $this->fake->grant('resource:3', 'tenant', 'tenant:b');
        $this->fake->grant('resource:4', 'tenant', 'tenant:b');

        return $this;
    }

    /**
     * Set up an organization hierarchy scenario.
     */
    public function organizationHierarchy(): self
    {
        $this->fake->reset();

        // Organization structure
        $this->fake->grant('user:ceo', 'admin', 'organization:acme');
        $this->fake->grant('user:cto', 'admin', 'organization:acme');
        $this->fake->grant('user:manager1', 'manager', 'department:engineering');
        $this->fake->grant('user:manager2', 'manager', 'department:sales');

        // Department members
        $this->fake->grant('user:dev1', 'member', 'department:engineering');
        $this->fake->grant('user:dev2', 'member', 'department:engineering');
        $this->fake->grant('user:sales1', 'member', 'department:sales');

        // Department belongs to organization
        $this->fake->grant('department:engineering', 'department', 'organization:acme');
        $this->fake->grant('department:sales', 'department', 'organization:acme');

        return $this;
    }

    /**
     * Add specific denials to current scenario.
     *
     * @param array $denials
     */
    public function withDenials(array $denials): self
    {
        foreach ($denials as $denial) {
            $this->fake->mockCheck(
                $denial['user'],
                $denial['relation'],
                $denial['object'],
                false,
            );
        }

        return $this;
    }

    /**
     * Set up delayed consistency scenario.
     *
     * @param int $delayMs
     */
    public function withEventualConsistency(int $delayMs = 100): self
    {
        // This would simulate eventual consistency in a real implementation
        // For testing, we can use the fake's existing behavior
        return $this;
    }

    /**
     * Set up failure scenarios for testing error handling.
     */
    public function withFailures(): self
    {
        // Configure the fake to fail on specific operations
        $this->fake->mockCheck('user:unauthorized', 'admin', 'system:main', false);
        $this->fake->mockCheck('user:banned', 'viewer', 'document:any', false);

        // Configure list operations to return empty
        $this->fake->mockListObjects('user:isolated', 'viewer', 'document', []);

        return $this;
    }

    /**
     * Add specific permissions to current scenario.
     *
     * @param array $permissions
     */
    public function withPermissions(array $permissions): self
    {
        foreach ($permissions as $permission) {
            $this->fake->grant(
                $permission['user'],
                $permission['relation'],
                $permission['object'],
            );
        }

        return $this;
    }

    /**
     * Set up a workflow approval scenario.
     */
    public function workflowApproval(): self
    {
        $this->fake->reset();

        // Approval chain
        $this->fake->grant('user:employee', 'submitter', 'request:expense-123');
        $this->fake->grant('user:manager', 'approver_level_1', 'request:expense-123');
        $this->fake->grant('user:director', 'approver_level_2', 'request:expense-123');
        $this->fake->grant('user:cfo', 'approver_level_3', 'request:expense-123');

        // Department relationships
        $this->fake->grant('user:employee', 'member', 'department:sales');
        $this->fake->grant('user:manager', 'manager', 'department:sales');
        $this->fake->grant('department:sales', 'department', 'organization:acme');

        return $this;
    }
}
