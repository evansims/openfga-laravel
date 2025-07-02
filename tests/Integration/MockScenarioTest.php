<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\{FakesOpenFga, UsesMockScenarios};
use OpenFGA\Laravel\Tests\Support\FeatureTestCase;

uses(FeatureTestCase::class, FakesOpenFga::class, UsesMockScenarios::class);

describe('Mock Scenario', function (): void {
    beforeEach(function (): void {
        $this->fakeOpenFga();
    });

    it('api access control scenario', function (): void {
        $this->withApiAccessControlScenario();

        // Mobile app permissions
        $this->assertScenarioPermissions([
            ['user' => 'client:mobile-app', 'relation' => 'read', 'object' => 'api:users'],
            ['user' => 'client:mobile-app', 'relation' => 'read', 'object' => 'api:posts'],
            ['user' => 'client:mobile-app', 'relation' => 'write', 'object' => 'api:users', 'expected' => false],
        ]);

        // Admin dashboard permissions
        $this->assertScenarioPermissions([
            ['user' => 'client:admin-dashboard', 'relation' => 'read', 'object' => 'api:users'],
            ['user' => 'client:admin-dashboard', 'relation' => 'write', 'object' => 'api:users'],
            ['user' => 'client:admin-dashboard', 'relation' => 'read', 'object' => 'api:analytics'],
        ]);

        // Rate limiting
        $this->assertScenarioPermission('client:mobile-app', 'standard_limit', 'api:rate-limiter');
        $this->assertScenarioPermission('client:admin-dashboard', 'premium_limit', 'api:rate-limiter');
    });

    it('basic user document scenario', function (): void {
        $this->withBasicUserDocumentScenario();

        // Owner has all permissions
        $this->assertScenarioPermissions([
            ['user' => 'user:1', 'relation' => 'owner', 'object' => 'document:1', 'expected' => true],
            ['user' => 'user:1', 'relation' => 'editor', 'object' => 'document:1', 'expected' => true],
            ['user' => 'user:1', 'relation' => 'viewer', 'object' => 'document:1', 'expected' => true],
        ]);

        // Editor can edit and view
        $this->assertScenarioPermissions([
            ['user' => 'user:2', 'relation' => 'owner', 'object' => 'document:1', 'expected' => false],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'document:1', 'expected' => true],
            ['user' => 'user:2', 'relation' => 'viewer', 'object' => 'document:1', 'expected' => true],
        ]);

        // Viewer can only view
        $this->assertScenarioPermissions([
            ['user' => 'user:3', 'relation' => 'owner', 'object' => 'document:1', 'expected' => false],
            ['user' => 'user:3', 'relation' => 'editor', 'object' => 'document:1', 'expected' => false],
            ['user' => 'user:3', 'relation' => 'viewer', 'object' => 'document:1', 'expected' => true],
        ]);
    });

    it('collaborative editing scenario', function (): void {
        $this->withCollaborativeEditingScenario();

        // Project lead has owner access
        $this->assertScenarioPermission('user:lead', 'owner', 'project:website');

        // Teams have collaborator access
        $this->assertScenarioPermission('team:frontend', 'collaborator', 'project:website');
        $this->assertScenarioPermission('team:backend', 'collaborator', 'project:website');

        // Team members through team relationship
        $this->assertScenarioPermission('user:designer1', 'member', 'team:frontend');
        $this->assertScenarioPermission('user:dev1', 'member', 'team:backend');

        // Documents belong to project
        $this->assertScenarioPermission('project:website', 'project', 'document:spec');
    });

    it('combining scenarios', function (): void {
        $this->scenarios(['basicUserDocument', 'organizationHierarchy']);

        // Both scenarios should be active
        $this->assertScenarioPermission('user:1', 'owner', 'document:1');
        $this->assertScenarioPermission('user:ceo', 'admin', 'organization:acme');
    });

    it('content moderation scenario', function (): void {
        $this->withContentModerationScenario();

        // Forum moderators
        $this->assertScenarioPermission('user:mod1', 'moderator', 'forum:general');
        $this->assertScenarioPermission('user:mod2', 'moderator', 'forum:tech');

        // Global moderator
        $this->assertScenarioPermission('user:admin', 'global_moderator', 'platform:main');

        // Post ownership
        $this->assertScenarioPermission('user:poster1', 'author', 'post:1');
        $this->assertScenarioPermission('post:1', 'post', 'forum:general');

        // Mod1 can moderate general forum posts but not tech forum
        $this->assertScenarioPermission('user:mod1', 'moderator', 'forum:general', true);
        $this->assertScenarioPermission('user:mod1', 'moderator', 'forum:tech', false);
    });

    it('custom scenario', function (): void {
        $this->customScenario(static function ($fake): void {
            // Set up a custom blog scenario
            $fake->grant('user:author', 'author', 'blog:tech');
            $fake->grant('user:editor', 'editor', 'blog:tech');
            $fake->grant('user:subscriber', 'subscriber', 'blog:tech');

            // Articles in blog
            $fake->grant('article:1', 'published_in', 'blog:tech');
            $fake->grant('article:2', 'published_in', 'blog:tech');

            // Author owns their article
            $fake->grant('user:author', 'owner', 'article:1');
        });

        // Verify custom scenario
        $this->assertScenarioPermission('user:author', 'author', 'blog:tech');
        $this->assertScenarioPermission('user:author', 'owner', 'article:1');
        $this->assertScenarioPermission('user:editor', 'editor', 'blog:tech');
    });

    it('extending scenario dynamically', function (): void {
        $this->withBasicUserDocumentScenario();

        // Add more permissions dynamically
        $this->grantInScenario('user:4', 'collaborator', 'document:1');
        $this->grantMultipleInScenario([
            ['user' => 'user:5', 'relation' => 'reviewer', 'object' => 'document:1'],
            ['user' => 'user:6', 'relation' => 'subscriber', 'object' => 'document:1'],
        ]);

        // Mock specific checks
        $this->mockCheckInScenario('user:7', 'admin', 'document:1', false);

        // Verify extended scenario
        $this->assertScenarioPermission('user:4', 'collaborator', 'document:1');
        $this->assertScenarioPermission('user:5', 'reviewer', 'document:1');
        $this->assertScenarioPermission('user:6', 'subscriber', 'document:1');
        $this->assertScenarioPermission('user:7', 'admin', 'document:1', false);
    });

    it('file system scenario', function (): void {
        $this->withFileSystemScenario();

        // Owner has access to root folder
        $this->assertScenarioPermission('user:owner', 'owner', 'folder:root');

        // Folder hierarchy
        $this->assertScenarioPermission('folder:documents', 'parent', 'folder:root');
        $this->assertScenarioPermission('folder:private', 'parent', 'folder:documents');

        // File locations
        $this->assertScenarioPermission('file:report.pdf', 'parent', 'folder:documents');
        $this->assertScenarioPermission('file:secret.doc', 'parent', 'folder:private');

        // Shared access
        $this->assertScenarioPermission('user:collaborator', 'viewer', 'file:report.pdf');
        $this->assertScenarioPermission('user:designer', 'editor', 'folder:images');
    });

    it('multi tenant scenario', function (): void {
        $this->withMultiTenantScenario();

        // Tenant isolation
        $this->assertScenarioPermissions([
            // Alice can access tenant A resources
            ['user' => 'user:alice', 'relation' => 'admin', 'object' => 'tenant:a', 'expected' => true],

            // But not tenant B
            ['user' => 'user:alice', 'relation' => 'admin', 'object' => 'tenant:b', 'expected' => false],

            // Charlie can access tenant B
            ['user' => 'user:charlie', 'relation' => 'admin', 'object' => 'tenant:b', 'expected' => true],

            // But not tenant A
            ['user' => 'user:charlie', 'relation' => 'admin', 'object' => 'tenant:a', 'expected' => false],
        ]);
    });

    it('organization hierarchy scenario', function (): void {
        $this->withOrganizationHierarchy();

        // CEO has admin access
        $this->assertScenarioPermission('user:ceo', 'admin', 'organization:acme');

        // Managers have department access
        $this->assertScenarioPermission('user:manager1', 'manager', 'department:engineering');
        $this->assertScenarioPermission('user:manager2', 'manager', 'department:sales');

        // Developers are members of engineering
        $this->assertScenarioPermission('user:dev1', 'member', 'department:engineering');
        $this->assertScenarioPermission('user:dev2', 'member', 'department:engineering');

        // Sales people can't access engineering
        $this->assertScenarioPermission('user:sales1', 'member', 'department:engineering', false);
    });

    it('scenario with additional permissions', function (): void {
        $this->scenario('basicUserDocument')
            ->withPermissions([
                ['user' => 'user:4', 'relation' => 'commenter', 'object' => 'document:1'],
                ['user' => 'user:5', 'relation' => 'reviewer', 'object' => 'document:1'],
            ]);

        // Original scenario permissions
        $this->assertScenarioPermission('user:1', 'owner', 'document:1');

        // Additional permissions
        $this->assertScenarioPermission('user:4', 'commenter', 'document:1');
        $this->assertScenarioPermission('user:5', 'reviewer', 'document:1');
    });

    it('scenario with failures', function (): void {
        $this->scenario('basicUserDocument')
            ->withFailures();

        // Normal permissions work
        $this->assertScenarioPermission('user:1', 'owner', 'document:1');

        // But specific failures are mocked
        $this->assertScenarioPermission('user:unauthorized', 'admin', 'system:main', false);
        $this->assertScenarioPermission('user:banned', 'viewer', 'document:any', false);
    });

    it('workflow approval scenario', function (): void {
        $this->withWorkflowApprovalScenario();

        // Employee submitted the request
        $this->assertScenarioPermission('user:employee', 'submitter', 'request:expense-123');

        // Approval chain
        $this->assertScenarioPermission('user:manager', 'approver_level_1', 'request:expense-123');
        $this->assertScenarioPermission('user:director', 'approver_level_2', 'request:expense-123');
        $this->assertScenarioPermission('user:cfo', 'approver_level_3', 'request:expense-123');

        // Employee is member of sales department
        $this->assertScenarioPermission('user:employee', 'member', 'department:sales');
    });
});
