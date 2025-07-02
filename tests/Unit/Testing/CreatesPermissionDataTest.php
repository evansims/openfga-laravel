<?php

declare(strict_types=1);

use OpenFGA\Laravel\Testing\{CreatesPermissionData, FakeOpenFga};
use OpenFGA\Laravel\Tests\TestCase;

uses(TestCase::class);

describe('CreatesPermissionData', function (): void {
    $createInstance = (static fn (): object => new class {
        use CreatesPermissionData {
            createDocumentHierarchy as public;
            createBlogSystem as public;
            createEcommerceSystem as public;
            createFileSystem as public;
            createNestedHierarchy as public;
            createOrganizationStructure as public;
            createProjectManagementSystem as public;
            createRandomPermissions as public;
        }
    });

    beforeEach(function () use ($createInstance): void {
        $this->fake = new FakeOpenFga;
        $this->instance = $createInstance();
    });

    it('factories can be combined', function (): void {
        // Create multiple systems in the same fake
        $doc_data = $this->instance->createDocumentHierarchy($this->fake);
        $blog_data = $this->instance->createBlogSystem($this->fake);

        // Test that both systems work
        expect($this->fake->check($doc_data['users']['owner'], 'owner', $doc_data['documents']['doc1']))->toBeTrue();
        expect($this->fake->check($blog_data['users']['admin'], 'admin', $blog_data['blog']))->toBeTrue();

        // Test that they're independent
        expect($this->fake->check($doc_data['users']['owner'], 'admin', $blog_data['blog']))->toBeFalse();
        expect($this->fake->check($blog_data['users']['admin'], 'owner', $doc_data['documents']['doc1']))->toBeFalse();
    });

    it('factories produce different data', function (): void {
        $fake1 = new FakeOpenFga;
        $fake2 = new FakeOpenFga;

        $doc_data = $this->instance->createDocumentHierarchy($fake1);
        $org_data = $this->instance->createOrganizationStructure($fake2);

        // Verify they have different structure
        expect($doc_data['users'])->not->toEqual($org_data['users']);
        expect($doc_data)->toHaveKey('documents');
        expect($org_data)->not->toHaveKey('documents');
        expect($org_data)->toHaveKey('departments');
        expect($doc_data)->not->toHaveKey('departments');

        // Verify different permissions were created
        expect($fake1->getTuples())->not->toEqual($fake2->getTuples());
    });

    it('creates blog system', function (): void {
        $data = $this->instance->createBlogSystem($this->fake);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('blog');
        expect($data)->toHaveKey('posts');
        expect($data)->toHaveKey('categories');

        // Test permissions
        expect($this->fake->check($data['users']['admin'], 'admin', $data['blog']))->toBeTrue();
        expect($this->fake->check($data['users']['author1'], 'author', $data['posts']['post1']))->toBeTrue();
        expect($this->fake->check($data['users']['subscriber'], 'reader', $data['posts']['post1']))->toBeTrue();
        expect($this->fake->check($data['users']['guest'], 'reader', $data['posts']['post1']))->toBeTrue(); // public post
        expect($this->fake->check($data['users']['guest'], 'reader', $data['posts']['post2']))->toBeFalse(); // private post
    });

    it('creates document hierarchy', function (): void {
        $data = $this->instance->createDocumentHierarchy($this->fake);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('documents');
        expect($data)->toHaveKey('folders');

        // Test permissions
        expect($this->fake->check($data['users']['owner'], 'owner', $data['documents']['doc1']))->toBeTrue();
        expect($this->fake->check($data['users']['editor'], 'editor', $data['documents']['doc1']))->toBeTrue();
        expect($this->fake->check($data['users']['viewer'], 'viewer', $data['documents']['doc1']))->toBeTrue();
        expect($this->fake->check($data['users']['viewer'], 'editor', $data['documents']['doc1']))->toBeFalse();
    });

    it('creates ecommerce system', function (): void {
        $data = $this->instance->createEcommerceSystem($this->fake);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('store');
        expect($data)->toHaveKey('products');
        expect($data)->toHaveKey('orders');

        // Test permissions
        expect($this->fake->check($data['users']['admin'], 'admin', $data['store']))->toBeTrue();
        expect($this->fake->check($data['users']['vendor1'], 'owner', $data['products']['product1']))->toBeTrue();
        expect($this->fake->check($data['users']['customer1'], 'owner', $data['orders']['order1']))->toBeTrue();
        expect($this->fake->check($data['users']['support'], 'view', $data['orders']['order1']))->toBeTrue();
        expect($this->fake->check($data['users']['customer1'], 'view', $data['orders']['order3']))->toBeFalse();
    });

    it('creates file system', function (): void {
        $data = $this->instance->createFileSystem($this->fake);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('folders');
        expect($data)->toHaveKey('files');

        // Test permissions
        expect($this->fake->check($data['users']['root'], 'owner', $data['folders']['root']))->toBeTrue();
        expect($this->fake->check($data['users']['user1'], 'owner', $data['folders']['user1_home']))->toBeTrue();
        expect($this->fake->check($data['users']['user1'], 'read', $data['files']['shared_file']))->toBeTrue();
        expect($this->fake->check($data['users']['guest'], 'read', $data['files']['shared_file']))->toBeTrue();
        expect($this->fake->check($data['users']['user2'], 'read', $data['files']['user1_doc']))->toBeFalse();
    });

    it('creates nested hierarchy', function (): void {
        $data = $this->instance->createNestedHierarchy($this->fake);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('hierarchy');

        // Test permissions
        expect($this->fake->check($data['users']['super_admin'], 'super_admin', $data['hierarchy']['company']))->toBeTrue();
        expect($this->fake->check($data['users']['org_admin'], 'admin', $data['hierarchy']['company']))->toBeTrue();
        expect($this->fake->check($data['users']['dept_manager'], 'manager', $data['hierarchy']['department']))->toBeTrue();
        expect($this->fake->check($data['users']['team_lead'], 'lead', $data['hierarchy']['team']))->toBeTrue();
        expect($this->fake->check($data['users']['employee'], 'contributor', $data['hierarchy']['project']))->toBeTrue();
    });

    it('creates organization structure', function (): void {
        $data = $this->instance->createOrganizationStructure($this->fake);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('organization');
        expect($data)->toHaveKey('departments');
        expect($data)->toHaveKey('projects');

        // Test permissions
        expect($this->fake->check($data['users']['ceo'], 'admin', $data['organization']))->toBeTrue();
        expect($this->fake->check($data['users']['hr_manager'], 'manager', $data['departments']['hr']))->toBeTrue();
        expect($this->fake->check($data['users']['developer'], 'contributor', $data['projects']['project1']))->toBeTrue();
        expect($this->fake->check($data['users']['intern'], 'observer', $data['projects']['project1']))->toBeTrue();
    });

    it('creates project management system', function (): void {
        $data = $this->instance->createProjectManagementSystem($this->fake);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('workspace');
        expect($data)->toHaveKey('teams');
        expect($data)->toHaveKey('projects');
        expect($data)->toHaveKey('tasks');

        // Test permissions
        expect($this->fake->check($data['users']['pm'], 'admin', $data['workspace']))->toBeTrue();
        expect($this->fake->check($data['users']['lead_dev'], 'lead', $data['teams']['development']))->toBeTrue();
        expect($this->fake->check($data['users']['dev1'], 'contributor', $data['projects']['project_alpha']))->toBeTrue();
        expect($this->fake->check($data['users']['client'], 'viewer', $data['projects']['project_alpha']))->toBeTrue();
        expect($this->fake->check($data['users']['client'], 'contributor', $data['projects']['project_alpha']))->toBeFalse();
    });

    it('creates random permissions', function (): void {
        $data = $this->instance->createRandomPermissions($this->fake, 5, 10, 3, 20);

        // Test structure
        expect($data)->toHaveKey('users');
        expect($data)->toHaveKey('objects');
        expect($data)->toHaveKey('relations');
        expect($data)->toHaveKey('tuples');

        // Test counts
        expect($data['users'])->toHaveCount(5);
        expect($data['objects'])->toHaveCount(10);
        expect($data['relations'])->toHaveCount(3);
        expect(count($data['tuples']))->toBeLessThanOrEqual(20); // May be less due to duplicate prevention

        // Test that some permissions were created
        expect(count($this->fake->getTuples()))->toBeGreaterThan(0);
    });
});
