<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Tests\Testing;

use OpenFGA\Laravel\Testing\CreatesPermissionData;
use OpenFGA\Laravel\Testing\FakeOpenFga;
use OpenFGA\Laravel\Tests\TestCase;

class CreatesPermissionDataTest extends TestCase
{
    use CreatesPermissionData;

    protected FakeOpenFga $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeOpenFga();
    }

    public function test_it_creates_document_hierarchy(): void
    {
        $data = $this->createDocumentHierarchy($this->fake);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('documents', $data);
        $this->assertArrayHasKey('folders', $data);

        // Test permissions
        $this->assertTrue($this->fake->check($data['users']['owner'], 'owner', $data['documents']['doc1']));
        $this->assertTrue($this->fake->check($data['users']['editor'], 'editor', $data['documents']['doc1']));
        $this->assertTrue($this->fake->check($data['users']['viewer'], 'viewer', $data['documents']['doc1']));
        $this->assertFalse($this->fake->check($data['users']['viewer'], 'editor', $data['documents']['doc1']));
    }

    public function test_it_creates_organization_structure(): void
    {
        $data = $this->createOrganizationStructure($this->fake);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('organization', $data);
        $this->assertArrayHasKey('departments', $data);
        $this->assertArrayHasKey('projects', $data);

        // Test permissions
        $this->assertTrue($this->fake->check($data['users']['ceo'], 'admin', $data['organization']));
        $this->assertTrue($this->fake->check($data['users']['hr_manager'], 'manager', $data['departments']['hr']));
        $this->assertTrue($this->fake->check($data['users']['developer'], 'contributor', $data['projects']['project1']));
        $this->assertTrue($this->fake->check($data['users']['intern'], 'observer', $data['projects']['project1']));
    }

    public function test_it_creates_blog_system(): void
    {
        $data = $this->createBlogSystem($this->fake);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('blog', $data);
        $this->assertArrayHasKey('posts', $data);
        $this->assertArrayHasKey('categories', $data);

        // Test permissions
        $this->assertTrue($this->fake->check($data['users']['admin'], 'admin', $data['blog']));
        $this->assertTrue($this->fake->check($data['users']['author1'], 'author', $data['posts']['post1']));
        $this->assertTrue($this->fake->check($data['users']['subscriber'], 'reader', $data['posts']['post1']));
        $this->assertTrue($this->fake->check($data['users']['guest'], 'reader', $data['posts']['post1'])); // public post
        $this->assertFalse($this->fake->check($data['users']['guest'], 'reader', $data['posts']['post2'])); // private post
    }

    public function test_it_creates_file_system(): void
    {
        $data = $this->createFileSystem($this->fake);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('folders', $data);
        $this->assertArrayHasKey('files', $data);

        // Test permissions
        $this->assertTrue($this->fake->check($data['users']['root'], 'owner', $data['folders']['root']));
        $this->assertTrue($this->fake->check($data['users']['user1'], 'owner', $data['folders']['user1_home']));
        $this->assertTrue($this->fake->check($data['users']['user1'], 'read', $data['files']['shared_file']));
        $this->assertTrue($this->fake->check($data['users']['guest'], 'read', $data['files']['shared_file']));
        $this->assertFalse($this->fake->check($data['users']['user2'], 'read', $data['files']['user1_doc']));
    }

    public function test_it_creates_ecommerce_system(): void
    {
        $data = $this->createEcommerceSystem($this->fake);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('store', $data);
        $this->assertArrayHasKey('products', $data);
        $this->assertArrayHasKey('orders', $data);

        // Test permissions
        $this->assertTrue($this->fake->check($data['users']['admin'], 'admin', $data['store']));
        $this->assertTrue($this->fake->check($data['users']['vendor1'], 'owner', $data['products']['product1']));
        $this->assertTrue($this->fake->check($data['users']['customer1'], 'owner', $data['orders']['order1']));
        $this->assertTrue($this->fake->check($data['users']['support'], 'view', $data['orders']['order1']));
        $this->assertFalse($this->fake->check($data['users']['customer1'], 'view', $data['orders']['order3']));
    }

    public function test_it_creates_project_management_system(): void
    {
        $data = $this->createProjectManagementSystem($this->fake);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('workspace', $data);
        $this->assertArrayHasKey('teams', $data);
        $this->assertArrayHasKey('projects', $data);
        $this->assertArrayHasKey('tasks', $data);

        // Test permissions
        $this->assertTrue($this->fake->check($data['users']['pm'], 'admin', $data['workspace']));
        $this->assertTrue($this->fake->check($data['users']['lead_dev'], 'lead', $data['teams']['development']));
        $this->assertTrue($this->fake->check($data['users']['dev1'], 'contributor', $data['projects']['project_alpha']));
        $this->assertTrue($this->fake->check($data['users']['client'], 'viewer', $data['projects']['project_alpha']));
        $this->assertFalse($this->fake->check($data['users']['client'], 'contributor', $data['projects']['project_alpha']));
    }

    public function test_it_creates_random_permissions(): void
    {
        $data = $this->createRandomPermissions($this->fake, 5, 10, 3, 20);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('objects', $data);
        $this->assertArrayHasKey('relations', $data);
        $this->assertArrayHasKey('tuples', $data);

        // Test counts
        $this->assertCount(5, $data['users']);
        $this->assertCount(10, $data['objects']);
        $this->assertCount(3, $data['relations']);
        $this->assertLessThanOrEqual(20, count($data['tuples'])); // May be less due to duplicate prevention

        // Test that some permissions were created
        $this->assertGreaterThan(0, count($this->fake->getTuples()));
    }

    public function test_it_creates_nested_hierarchy(): void
    {
        $data = $this->createNestedHierarchy($this->fake);

        // Test structure
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('hierarchy', $data);

        // Test permissions
        $this->assertTrue($this->fake->check($data['users']['super_admin'], 'super_admin', $data['hierarchy']['company']));
        $this->assertTrue($this->fake->check($data['users']['org_admin'], 'admin', $data['hierarchy']['company']));
        $this->assertTrue($this->fake->check($data['users']['dept_manager'], 'manager', $data['hierarchy']['department']));
        $this->assertTrue($this->fake->check($data['users']['team_lead'], 'lead', $data['hierarchy']['team']));
        $this->assertTrue($this->fake->check($data['users']['employee'], 'contributor', $data['hierarchy']['project']));
    }

    public function test_factories_produce_different_data(): void
    {
        $fake1 = new FakeOpenFga();
        $fake2 = new FakeOpenFga();

        $doc_data = $this->createDocumentHierarchy($fake1);
        $org_data = $this->createOrganizationStructure($fake2);

        // Verify they have different structure
        $this->assertNotEquals($doc_data['users'], $org_data['users']);
        $this->assertArrayHasKey('documents', $doc_data);
        $this->assertArrayNotHasKey('documents', $org_data);
        $this->assertArrayHasKey('departments', $org_data);
        $this->assertArrayNotHasKey('departments', $doc_data);

        // Verify different permissions were created
        $this->assertNotEquals($fake1->getTuples(), $fake2->getTuples());
    }

    public function test_factories_can_be_combined(): void
    {
        // Create multiple systems in the same fake
        $doc_data = $this->createDocumentHierarchy($this->fake);
        $blog_data = $this->createBlogSystem($this->fake);

        // Test that both systems work
        $this->assertTrue($this->fake->check($doc_data['users']['owner'], 'owner', $doc_data['documents']['doc1']));
        $this->assertTrue($this->fake->check($blog_data['users']['admin'], 'admin', $blog_data['blog']));

        // Test that they're independent
        $this->assertFalse($this->fake->check($doc_data['users']['owner'], 'admin', $blog_data['blog']));
        $this->assertFalse($this->fake->check($blog_data['users']['admin'], 'owner', $doc_data['documents']['doc1']));
    }
}