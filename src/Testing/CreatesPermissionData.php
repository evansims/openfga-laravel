<?php

declare(strict_types=1);

namespace OpenFGA\Laravel\Testing;

use function array_slice;
use function in_array;
use function sprintf;

/**
 * Trait for creating common permission test data scenarios.
 */
trait CreatesPermissionData // @phpstan-ignore trait.unused
{
    /**
     * Create a blog system with authors, editors, and subscribers.
     *
     * @param FakeOpenFga $fake
     */
    protected function createBlogSystem(FakeOpenFga $fake): array
    {
        $data = [
            'users' => [
                'admin' => 'user:admin',
                'author1' => 'user:author1',
                'author2' => 'user:author2',
                'editor' => 'user:editor',
                'subscriber' => 'user:subscriber',
                'guest' => 'user:guest',
            ],
            'blog' => 'blog:main',
            'posts' => [
                'post1' => 'post:1',
                'post2' => 'post:2',
                'post3' => 'post:3',
            ],
            'categories' => [
                'tech' => 'category:tech',
                'lifestyle' => 'category:lifestyle',
            ],
        ];

        // Blog administration
        $fake->grant($data['users']['admin'], 'admin', $data['blog']);

        // Editorial permissions
        $fake->grant($data['users']['editor'], 'editor', $data['blog']);

        // Author permissions on their own posts
        $fake->grant($data['users']['author1'], 'author', $data['posts']['post1']);
        $fake->grant($data['users']['author2'], 'author', $data['posts']['post2']);
        $fake->grant($data['users']['author1'], 'author', $data['posts']['post3']);

        // Reading permissions
        $fake->grant($data['users']['subscriber'], 'subscriber', $data['blog']);
        $fake->grant($data['users']['guest'], 'reader', $data['posts']['post1']); // public post
        $fake->grant($data['users']['subscriber'], 'reader', $data['posts']['post1']);
        $fake->grant($data['users']['subscriber'], 'reader', $data['posts']['post2']);
        $fake->grant($data['users']['subscriber'], 'reader', $data['posts']['post3']);

        // Category permissions
        $fake->grant($data['users']['author1'], 'contributor', $data['categories']['tech']);
        $fake->grant($data['users']['author2'], 'contributor', $data['categories']['lifestyle']);

        return $data;
    }

    /**
     * Create a simple document hierarchy with owner, editor, and viewer roles.
     *
     * @param FakeOpenFga $fake
     */
    protected function createDocumentHierarchy(FakeOpenFga $fake): array
    {
        $data = [
            'users' => [
                'owner' => 'user:owner',
                'editor' => 'user:editor',
                'viewer' => 'user:viewer',
                'admin' => 'user:admin',
            ],
            'documents' => [
                'doc1' => 'document:1',
                'doc2' => 'document:2',
                'doc3' => 'document:3',
            ],
            'folders' => [
                'folder1' => 'folder:1',
                'folder2' => 'folder:2',
            ],
        ];

        // Document ownership
        $fake->grant($data['users']['owner'], 'owner', $data['documents']['doc1']);
        $fake->grant($data['users']['admin'], 'owner', $data['documents']['doc2']);

        // Document editing
        $fake->grant($data['users']['editor'], 'editor', $data['documents']['doc1']);
        $fake->grant($data['users']['owner'], 'editor', $data['documents']['doc1']); // owners can edit
        $fake->grant($data['users']['editor'], 'editor', $data['documents']['doc2']);

        // Document viewing
        $fake->grant($data['users']['viewer'], 'viewer', $data['documents']['doc1']);
        $fake->grant($data['users']['editor'], 'viewer', $data['documents']['doc1']); // editors can view
        $fake->grant($data['users']['owner'], 'viewer', $data['documents']['doc1']); // owners can view
        $fake->grant($data['users']['viewer'], 'viewer', $data['documents']['doc2']);

        // Folder permissions
        $fake->grant($data['users']['admin'], 'admin', $data['folders']['folder1']);
        $fake->grant($data['users']['owner'], 'member', $data['folders']['folder1']);
        $fake->grant($data['users']['editor'], 'member', $data['folders']['folder1']);

        return $data;
    }

    /**
     * Create an e-commerce system with customers, orders, and products.
     *
     * @param FakeOpenFga $fake
     */
    protected function createEcommerceSystem(FakeOpenFga $fake): array
    {
        $data = [
            'users' => [
                'admin' => 'user:admin',
                'vendor1' => 'user:vendor1',
                'vendor2' => 'user:vendor2',
                'customer1' => 'user:customer1',
                'customer2' => 'user:customer2',
                'support' => 'user:support',
            ],
            'store' => 'store:main',
            'products' => [
                'product1' => 'product:1',
                'product2' => 'product:2',
                'product3' => 'product:3',
            ],
            'orders' => [
                'order1' => 'order:1',
                'order2' => 'order:2',
                'order3' => 'order:3',
            ],
            'categories' => [
                'electronics' => 'category:electronics',
                'books' => 'category:books',
            ],
        ];

        // Store administration
        $fake->grant($data['users']['admin'], 'admin', $data['store']);

        // Vendor permissions
        $fake->grant($data['users']['vendor1'], 'vendor', $data['store']);
        $fake->grant($data['users']['vendor2'], 'vendor', $data['store']);

        // Product ownership
        $fake->grant($data['users']['vendor1'], 'owner', $data['products']['product1']);
        $fake->grant($data['users']['vendor1'], 'owner', $data['products']['product2']);
        $fake->grant($data['users']['vendor2'], 'owner', $data['products']['product3']);

        // Product management
        $fake->grant($data['users']['vendor1'], 'manage', $data['products']['product1']);
        $fake->grant($data['users']['vendor1'], 'manage', $data['products']['product2']);
        $fake->grant($data['users']['vendor2'], 'manage', $data['products']['product3']);

        // Customer orders
        $fake->grant($data['users']['customer1'], 'owner', $data['orders']['order1']);
        $fake->grant($data['users']['customer1'], 'owner', $data['orders']['order2']);
        $fake->grant($data['users']['customer2'], 'owner', $data['orders']['order3']);

        // Order viewing
        $fake->grant($data['users']['customer1'], 'view', $data['orders']['order1']);
        $fake->grant($data['users']['customer1'], 'view', $data['orders']['order2']);
        $fake->grant($data['users']['customer2'], 'view', $data['orders']['order3']);

        // Support access
        $fake->grant($data['users']['support'], 'support', $data['store']);
        $fake->grant($data['users']['support'], 'view', $data['orders']['order1']);
        $fake->grant($data['users']['support'], 'view', $data['orders']['order2']);
        $fake->grant($data['users']['support'], 'view', $data['orders']['order3']);

        // Category management
        $fake->grant($data['users']['admin'], 'manage', $data['categories']['electronics']);
        $fake->grant($data['users']['admin'], 'manage', $data['categories']['books']);

        return $data;
    }

    /**
     * Create a file system structure with folders and files.
     *
     * @param FakeOpenFga $fake
     */
    protected function createFileSystem(FakeOpenFga $fake): array
    {
        $data = [
            'users' => [
                'root' => 'user:root',
                'admin' => 'user:admin',
                'user1' => 'user:user1',
                'user2' => 'user:user2',
                'guest' => 'user:guest',
            ],
            'folders' => [
                'root' => 'folder:/',
                'home' => 'folder:/home',
                'user1_home' => 'folder:/home/user1',
                'user2_home' => 'folder:/home/user2',
                'shared' => 'folder:/shared',
                'tmp' => 'folder:/tmp',
            ],
            'files' => [
                'config' => 'file:/etc/config',
                'user1_doc' => 'file:/home/user1/document.txt',
                'user2_doc' => 'file:/home/user2/document.txt',
                'shared_file' => 'file:/shared/public.txt',
                'temp_file' => 'file:/tmp/temp.txt',
            ],
        ];

        // Root permissions
        $fake->grant($data['users']['root'], 'owner', $data['folders']['root']);
        $fake->grant($data['users']['root'], 'read', $data['folders']['root']);
        $fake->grant($data['users']['root'], 'write', $data['folders']['root']);
        $fake->grant($data['users']['root'], 'execute', $data['folders']['root']);

        // Admin permissions
        $fake->grant($data['users']['admin'], 'admin', $data['folders']['home']);
        $fake->grant($data['users']['admin'], 'read', $data['folders']['home']);
        $fake->grant($data['users']['admin'], 'write', $data['folders']['shared']);

        // User home directories
        $fake->grant($data['users']['user1'], 'owner', $data['folders']['user1_home']);
        $fake->grant($data['users']['user1'], 'read', $data['folders']['user1_home']);
        $fake->grant($data['users']['user1'], 'write', $data['folders']['user1_home']);
        $fake->grant($data['users']['user1'], 'execute', $data['folders']['user1_home']);

        $fake->grant($data['users']['user2'], 'owner', $data['folders']['user2_home']);
        $fake->grant($data['users']['user2'], 'read', $data['folders']['user2_home']);
        $fake->grant($data['users']['user2'], 'write', $data['folders']['user2_home']);
        $fake->grant($data['users']['user2'], 'execute', $data['folders']['user2_home']);

        // File permissions
        $fake->grant($data['users']['user1'], 'owner', $data['files']['user1_doc']);
        $fake->grant($data['users']['user1'], 'read', $data['files']['user1_doc']);
        $fake->grant($data['users']['user1'], 'write', $data['files']['user1_doc']);

        $fake->grant($data['users']['user2'], 'owner', $data['files']['user2_doc']);
        $fake->grant($data['users']['user2'], 'read', $data['files']['user2_doc']);
        $fake->grant($data['users']['user2'], 'write', $data['files']['user2_doc']);

        // Shared permissions
        $fake->grant($data['users']['user1'], 'read', $data['files']['shared_file']);
        $fake->grant($data['users']['user2'], 'read', $data['files']['shared_file']);
        $fake->grant($data['users']['guest'], 'read', $data['files']['shared_file']);

        // Temporary files - everyone can write
        $fake->grant($data['users']['user1'], 'write', $data['folders']['tmp']);
        $fake->grant($data['users']['user2'], 'write', $data['folders']['tmp']);
        $fake->grant($data['users']['guest'], 'write', $data['folders']['tmp']);

        return $data;
    }

    /**
     * Create a complex nested hierarchy for testing inheritance.
     *
     * @param FakeOpenFga $fake
     */
    protected function createNestedHierarchy(FakeOpenFga $fake): array
    {
        $data = [
            'users' => [
                'super_admin' => 'user:super_admin',
                'org_admin' => 'user:org_admin',
                'dept_manager' => 'user:dept_manager',
                'team_lead' => 'user:team_lead',
                'employee' => 'user:employee',
                'contractor' => 'user:contractor',
            ],
            'hierarchy' => [
                'company' => 'company:acme',
                'division' => 'division:engineering',
                'department' => 'department:backend',
                'team' => 'team:platform',
                'project' => 'project:api_v2',
            ],
        ];

        // Company level
        $fake->grant($data['users']['super_admin'], 'super_admin', $data['hierarchy']['company']);
        $fake->grant($data['users']['org_admin'], 'admin', $data['hierarchy']['company']);

        // Division level
        $fake->grant($data['users']['org_admin'], 'admin', $data['hierarchy']['division']);

        // Department level
        $fake->grant($data['users']['dept_manager'], 'manager', $data['hierarchy']['department']);
        $fake->grant($data['users']['org_admin'], 'admin', $data['hierarchy']['department']);

        // Team level
        $fake->grant($data['users']['team_lead'], 'lead', $data['hierarchy']['team']);
        $fake->grant($data['users']['dept_manager'], 'manager', $data['hierarchy']['team']);

        // Project level
        $fake->grant($data['users']['employee'], 'contributor', $data['hierarchy']['project']);
        $fake->grant($data['users']['contractor'], 'contributor', $data['hierarchy']['project']);
        $fake->grant($data['users']['team_lead'], 'lead', $data['hierarchy']['project']);

        // Cross-cutting concerns
        $fake->grant($data['users']['employee'], 'employee', $data['hierarchy']['company']);
        $fake->grant($data['users']['contractor'], 'contractor', $data['hierarchy']['company']);

        return $data;
    }

    /**
     * Create an organization with departments and roles.
     *
     * @param FakeOpenFga $fake
     */
    protected function createOrganizationStructure(FakeOpenFga $fake): array
    {
        $data = [
            'users' => [
                'ceo' => 'user:ceo',
                'hr_manager' => 'user:hr_manager',
                'it_manager' => 'user:it_manager',
                'developer' => 'user:developer',
                'designer' => 'user:designer',
                'intern' => 'user:intern',
            ],
            'organization' => 'organization:company',
            'departments' => [
                'hr' => 'department:hr',
                'it' => 'department:it',
                'design' => 'department:design',
            ],
            'projects' => [
                'project1' => 'project:1',
                'project2' => 'project:2',
            ],
        ];

        // Organization roles
        $fake->grant($data['users']['ceo'], 'admin', $data['organization']);

        // Department management
        $fake->grant($data['users']['hr_manager'], 'manager', $data['departments']['hr']);
        $fake->grant($data['users']['it_manager'], 'manager', $data['departments']['it']);

        // Department membership
        $fake->grant($data['users']['hr_manager'], 'member', $data['departments']['hr']);
        $fake->grant($data['users']['it_manager'], 'member', $data['departments']['it']);
        $fake->grant($data['users']['developer'], 'member', $data['departments']['it']);
        $fake->grant($data['users']['designer'], 'member', $data['departments']['design']);
        $fake->grant($data['users']['intern'], 'member', $data['departments']['it']);

        // Project access
        $fake->grant($data['users']['it_manager'], 'lead', $data['projects']['project1']);
        $fake->grant($data['users']['developer'], 'contributor', $data['projects']['project1']);
        $fake->grant($data['users']['designer'], 'contributor', $data['projects']['project1']);
        $fake->grant($data['users']['intern'], 'observer', $data['projects']['project1']);

        $fake->grant($data['users']['designer'], 'lead', $data['projects']['project2']);
        $fake->grant($data['users']['developer'], 'contributor', $data['projects']['project2']);

        return $data;
    }

    /**
     * Create a team-based project management system.
     *
     * @param FakeOpenFga $fake
     */
    protected function createProjectManagementSystem(FakeOpenFga $fake): array
    {
        $data = [
            'users' => [
                'pm' => 'user:project_manager',
                'lead_dev' => 'user:lead_developer',
                'dev1' => 'user:developer1',
                'dev2' => 'user:developer2',
                'qa' => 'user:qa_engineer',
                'designer' => 'user:designer',
                'client' => 'user:client',
            ],
            'workspace' => 'workspace:main',
            'teams' => [
                'development' => 'team:development',
                'design' => 'team:design',
                'qa' => 'team:qa',
            ],
            'projects' => [
                'project_alpha' => 'project:alpha',
                'project_beta' => 'project:beta',
            ],
            'tasks' => [
                'task1' => 'task:1',
                'task2' => 'task:2',
                'task3' => 'task:3',
                'task4' => 'task:4',
            ],
        ];

        // Workspace administration
        $fake->grant($data['users']['pm'], 'admin', $data['workspace']);

        // Team leadership
        $fake->grant($data['users']['lead_dev'], 'lead', $data['teams']['development']);
        $fake->grant($data['users']['designer'], 'lead', $data['teams']['design']);
        $fake->grant($data['users']['qa'], 'lead', $data['teams']['qa']);

        // Team membership
        $fake->grant($data['users']['lead_dev'], 'member', $data['teams']['development']);
        $fake->grant($data['users']['dev1'], 'member', $data['teams']['development']);
        $fake->grant($data['users']['dev2'], 'member', $data['teams']['development']);
        $fake->grant($data['users']['designer'], 'member', $data['teams']['design']);
        $fake->grant($data['users']['qa'], 'member', $data['teams']['qa']);

        // Project management
        $fake->grant($data['users']['pm'], 'manage', $data['projects']['project_alpha']);
        $fake->grant($data['users']['pm'], 'manage', $data['projects']['project_beta']);

        // Project access
        $fake->grant($data['users']['lead_dev'], 'contributor', $data['projects']['project_alpha']);
        $fake->grant($data['users']['dev1'], 'contributor', $data['projects']['project_alpha']);
        $fake->grant($data['users']['designer'], 'contributor', $data['projects']['project_alpha']);
        $fake->grant($data['users']['qa'], 'contributor', $data['projects']['project_alpha']);
        $fake->grant($data['users']['client'], 'viewer', $data['projects']['project_alpha']);

        $fake->grant($data['users']['lead_dev'], 'contributor', $data['projects']['project_beta']);
        $fake->grant($data['users']['dev2'], 'contributor', $data['projects']['project_beta']);

        // Task assignments
        $fake->grant($data['users']['dev1'], 'assignee', $data['tasks']['task1']);
        $fake->grant($data['users']['dev2'], 'assignee', $data['tasks']['task2']);
        $fake->grant($data['users']['designer'], 'assignee', $data['tasks']['task3']);
        $fake->grant($data['users']['qa'], 'assignee', $data['tasks']['task4']);

        // Task viewing
        $fake->grant($data['users']['pm'], 'view', $data['tasks']['task1']);
        $fake->grant($data['users']['pm'], 'view', $data['tasks']['task2']);
        $fake->grant($data['users']['pm'], 'view', $data['tasks']['task3']);
        $fake->grant($data['users']['pm'], 'view', $data['tasks']['task4']);
        $fake->grant($data['users']['lead_dev'], 'view', $data['tasks']['task1']);
        $fake->grant($data['users']['lead_dev'], 'view', $data['tasks']['task2']);

        return $data;
    }

    /**
     * Create random permission data for stress testing.
     *
     * @param int         $userCount     Number of users to create
     * @param int         $objectCount   Number of objects to create
     * @param int         $relationCount Number of different relations
     * @param int         $tupleCount    Number of permission tuples to create
     * @param FakeOpenFga $fake
     */
    protected function createRandomPermissions(FakeOpenFga $fake, int $userCount = 10, int $objectCount = 20, int $relationCount = 5, int $tupleCount = 100): array
    {
        $relations = ['read', 'write', 'admin', 'view', 'edit'];
        $relations = array_slice($relations, 0, $relationCount);

        $data = [
            'users' => [],
            'objects' => [],
            'relations' => $relations,
            'tuples' => [],
        ];

        // Generate users
        for ($i = 1; $i <= $userCount; ++$i) {
            $data['users'][] = 'user:' . $i;
        }

        // Generate objects
        for ($i = 1; $i <= $objectCount; ++$i) {
            $data['objects'][] = 'object:' . $i;
        }

        // Generate random tuples
        for ($i = 0; $i < $tupleCount; ++$i) {
            $user = $data['users'][array_rand($data['users'])];
            $relation = $relations[array_rand($relations)];
            $object = $data['objects'][array_rand($data['objects'])];

            // Avoid duplicates
            $tupleKey = sprintf('%s#%s@%s', $user, $relation, $object);

            if (! in_array($tupleKey, $data['tuples'], true)) {
                $fake->grant($user, $relation, $object);
                $data['tuples'][] = $tupleKey;
            }
        }

        return $data;
    }
}
