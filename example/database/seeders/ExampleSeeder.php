<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use OpenFGA\Laravel\Database\PermissionSeeder;

class ExampleSeeder extends PermissionSeeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command?->info('🌱 Seeding OpenFGA Laravel Example Data...');

        // Create organizations
        $organizations = $this->createOrganizations();
        
        // Create users
        $users = $this->createUsers();
        
        // Create departments and teams
        $departments = $this->createDepartments($organizations);
        $teams = $this->createTeams($departments);
        
        // Create folders and documents
        $folders = $this->createFolders($organizations, $departments, $teams);
        $this->createDocuments($users, $folders, $teams);
        
        // Set up comprehensive permissions
        $this->seedPermissions();

        $this->command?->info('✅ Example data seeded successfully!');
        $this->command?->info('');
        $this->command?->info('Demo Users Created:');
        $this->command?->info('- admin@example.com (Organization Admin)');
        $this->command?->info('- manager@example.com (Department Manager)');
        $this->command?->info('- lead@example.com (Team Lead)');
        $this->command?->info('- editor@example.com (Content Editor)');
        $this->command?->info('- viewer@example.com (Content Viewer)');
        $this->command?->info('');
        $this->command?->info('Password for all users: password');
    }

    /**
     * Define and seed the permissions.
     */
    protected function seedPermissions(): void
    {
        $this->command?->info('🔐 Setting up permissions...');

        // Get all created entities
        $organizations = Organization::all();
        $departments = Department::all();
        $teams = Team::all();
        $users = User::all()->keyBy('email');
        $documents = Document::all();
        $folders = Folder::all();

        foreach ($organizations as $organization) {
            $this->setupOrganizationPermissions($organization, $users);
        }

        foreach ($departments as $department) {
            $this->setupDepartmentPermissions($department, $users);
        }

        foreach ($teams as $team) {
            $this->setupTeamPermissions($team, $users);
        }

        foreach ($folders as $folder) {
            $this->setupFolderPermissions($folder, $users);
        }

        foreach ($documents as $document) {
            $this->setupDocumentPermissions($document, $users);
        }
    }

    private function createOrganizations(): array
    {
        $this->command?->info('Creating organizations...');

        return [
            Organization::create([
                'name' => 'Acme Corporation',
                'slug' => 'acme',
                'description' => 'A leading technology company focused on innovation.',
                'settings' => [
                    'allow_public_documents' => false,
                    'require_approval_for_publishing' => true,
                ]
            ]),
            Organization::create([
                'name' => 'StartupCo',
                'slug' => 'startupco',
                'description' => 'A fast-growing startup in the fintech space.',
                'settings' => [
                    'allow_public_documents' => true,
                    'require_approval_for_publishing' => false,
                ]
            ]),
        ];
    }

    private function createUsers(): array
    {
        $this->command?->info('Creating users...');

        return [
            'admin' => User::create([
                'name' => 'System Administrator',
                'email' => 'admin@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]),
            'manager' => User::create([
                'name' => 'Department Manager',
                'email' => 'manager@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]),
            'lead' => User::create([
                'name' => 'Team Lead',
                'email' => 'lead@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]),
            'editor' => User::create([
                'name' => 'Content Editor',
                'email' => 'editor@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]),
            'viewer' => User::create([
                'name' => 'Content Viewer',
                'email' => 'viewer@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]),
        ];
    }

    private function createDepartments(array $organizations): array
    {
        $this->command?->info('Creating departments...');

        $departments = [];
        
        foreach ($organizations as $org) {
            $departments[] = Department::create([
                'name' => 'Engineering',
                'description' => 'Software development and technical operations.',
                'organization_id' => $org->id,
            ]);
            
            $departments[] = Department::create([
                'name' => 'Marketing',
                'description' => 'Brand management and customer outreach.',
                'organization_id' => $org->id,
            ]);
            
            $departments[] = Department::create([
                'name' => 'Operations',
                'description' => 'Business operations and administration.',
                'organization_id' => $org->id,
            ]);
        }

        return $departments;
    }

    private function createTeams(array $departments): array
    {
        $this->command?->info('Creating teams...');

        $teams = [];
        
        foreach ($departments as $dept) {
            if ($dept->name === 'Engineering') {
                $teams[] = Team::create([
                    'name' => 'Backend Team',
                    'description' => 'API development and database management.',
                    'department_id' => $dept->id,
                ]);
                
                $teams[] = Team::create([
                    'name' => 'Frontend Team',
                    'description' => 'User interface and user experience.',
                    'department_id' => $dept->id,
                ]);
            } elseif ($dept->name === 'Marketing') {
                $teams[] = Team::create([
                    'name' => 'Content Team',
                    'description' => 'Content creation and management.',
                    'department_id' => $dept->id,
                ]);
            }
        }

        return $teams;
    }

    private function createFolders(array $organizations, array $departments, array $teams): array
    {
        $this->command?->info('Creating folders...');

        $folders = [];
        
        foreach ($organizations as $org) {
            $folders[] = Folder::create([
                'name' => 'Company Policies',
                'description' => 'Official company policies and procedures.',
                'organization_id' => $org->id,
            ]);
            
            $folders[] = Folder::create([
                'name' => 'Marketing Materials',
                'description' => 'Brand assets and marketing content.',
                'organization_id' => $org->id,
            ]);
        }

        foreach ($teams as $team) {
            $folders[] = Folder::create([
                'name' => $team->name . ' Documents',
                'description' => "Internal documents for {$team->name}.",
                'team_id' => $team->id,
            ]);
        }

        return $folders;
    }

    private function createDocuments(array $users, array $folders, array $teams): void
    {
        $this->command?->info('Creating documents...');

        $sampleContents = [
            'employee_handbook' => [
                'title' => 'Employee Handbook',
                'content' => "# Employee Handbook\n\nWelcome to our company! This handbook contains important information about our policies, procedures, and company culture.\n\n## Code of Conduct\n\nWe expect all employees to maintain the highest standards of professional conduct...",
                'excerpt' => 'Comprehensive guide for all employees covering policies and procedures.',
            ],
            'product_roadmap' => [
                'title' => 'Product Roadmap Q1 2024',
                'content' => "# Product Roadmap - Q1 2024\n\n## Objectives\n\n1. Launch new user dashboard\n2. Implement advanced analytics\n3. Improve mobile responsiveness\n\n## Timeline\n\n### January\n- UI/UX design completion\n- Backend API development\n\n### February\n- Frontend implementation\n- Initial testing phase\n\n### March\n- Beta testing\n- Production deployment",
                'excerpt' => 'Strategic product development plan for the first quarter.',
            ],
            'meeting_notes' => [
                'title' => 'Team Standup - Week 1',
                'content' => "# Team Standup Notes\n\n**Date:** January 8, 2024\n**Attendees:** Development Team\n\n## Progress Updates\n\n- Sarah: Completed user authentication module\n- Mike: Working on payment integration\n- Lisa: Finalizing mobile app design\n\n## Blockers\n\n- Waiting for API documentation from third-party service\n- Need approval for new server infrastructure\n\n## Action Items\n\n1. Schedule meeting with DevOps team\n2. Review security audit requirements\n3. Plan next sprint activities",
                'excerpt' => 'Weekly team progress and planning notes.',
            ],
            'brand_guidelines' => [
                'title' => 'Brand Guidelines',
                'content' => "# Brand Guidelines\n\n## Logo Usage\n\nOur logo should always be used in accordance with these guidelines to maintain brand consistency.\n\n### Colors\n\n- Primary: #007bff\n- Secondary: #6c757d\n- Accent: #28a745\n\n### Typography\n\n- Headings: Roboto Bold\n- Body: Open Sans Regular\n\n### Voice and Tone\n\nOur brand voice should be:\n- Professional yet approachable\n- Clear and concise\n- Helpful and informative",
                'excerpt' => 'Official brand identity and usage guidelines.',
            ],
        ];

        foreach ($sampleContents as $key => $content) {
            // Assign documents to different folders and owners
            $folder = $folders[array_rand($folders)];
            $owner = $users[array_rand($users)];
            
            Document::create([
                'title' => $content['title'],
                'content' => $content['content'],
                'excerpt' => $content['excerpt'],
                'status' => rand(0, 1) ? 'published' : 'draft',
                'owner_id' => $owner->id,
                'folder_id' => $folder->id,
                'metadata' => [
                    'tags' => ['example', 'demo'],
                    'category' => $key,
                ],
            ]);
        }

        // Create team-specific documents
        foreach ($teams as $team) {
            Document::create([
                'title' => "{$team->name} Project Plan",
                'content' => "# {$team->name} Project Plan\n\nThis document outlines the key objectives and milestones for our team.\n\n## Goals\n\n1. Improve team productivity\n2. Enhance code quality\n3. Meet project deadlines\n\n## Resources\n\n- Team members: 5\n- Budget: \$50,000\n- Timeline: 3 months",
                'excerpt' => "Project planning document for {$team->name}.",
                'status' => 'published',
                'owner_id' => $users['lead']->id,
                'team_id' => $team->id,
                'metadata' => [
                    'tags' => ['project', 'planning'],
                    'priority' => 'high',
                ],
            ]);
        }
    }

    private function setupOrganizationPermissions(Organization $organization, $users): void
    {
        $permissions = [];
        
        // Admin has full organization access
        $permissions[] = [
            'user' => $users['admin']->authorizationUser(),
            'relation' => 'admin',
            'object' => $organization->authorizationObject(),
        ];
        
        // Manager has management access
        $permissions[] = [
            'user' => $users['manager']->authorizationUser(),
            'relation' => 'manager',
            'object' => $organization->authorizationObject(),
        ];
        
        // All users are members
        foreach ($users as $user) {
            $permissions[] = [
                'user' => $user->authorizationUser(),
                'relation' => 'member',
                'object' => $organization->authorizationObject(),
            ];
        }
        
        // Write permissions in batch
        $this->writePermissions($permissions);

        if ($this->command) {
            $this->command->info("  ✓ Organization: {$organization->name}");
        }
    }

    private function setupDepartmentPermissions(Department $department, $users): void
    {
        // Manager manages all departments in their org
        $this->grant($users['manager']->authorizationUser(), 'manager', $department->authorizationObject());
        
        // Team members belong to relevant departments
        if (str_contains($department->name, 'Engineering')) {
            $this->grant($users['lead']->authorizationUser(), 'member', $department->authorizationObject());
            $this->grant($users['editor']->authorizationUser(), 'member', $department->authorizationObject());
        } elseif (str_contains($department->name, 'Marketing')) {
            $this->grant($users['viewer']->authorizationUser(), 'member', $department->authorizationObject());
        }
    }

    private function setupTeamPermissions(Team $team, $users): void
    {
        if (str_contains($team->name, 'Backend') || str_contains($team->name, 'Frontend')) {
            // Engineering teams
            $this->grant($users['lead']->authorizationUser(), 'lead', $team->authorizationObject());
            $this->grant($users['editor']->authorizationUser(), 'member', $team->authorizationObject());
        } elseif (str_contains($team->name, 'Content')) {
            // Marketing teams
            $this->grant($users['viewer']->authorizationUser(), 'member', $team->authorizationObject());
        }

        if ($this->command) {
            $this->command->info("  ✓ Team: {$team->name}");
        }
    }

    private function setupFolderPermissions(Folder $folder, $users): void
    {
        if (str_contains($folder->name, 'Policies')) {
            // Company policies - admin can manage, others can view
            $this->grant($users['admin']->authorizationUser(), 'admin', $folder->authorizationObject());
            $this->grant($users['manager']->authorizationUser(), 'editor', $folder->authorizationObject());
            
            foreach (['lead', 'editor', 'viewer'] as $role) {
                $this->grant($users[$role]->authorizationUser(), 'viewer', $folder->authorizationObject());
            }
        } elseif (str_contains($folder->name, 'Marketing')) {
            // Marketing materials
            $this->grant($users['manager']->authorizationUser(), 'admin', $folder->authorizationObject());
            $this->grant($users['viewer']->authorizationUser(), 'editor', $folder->authorizationObject());
        } else {
            // Team folders
            $this->grant($users['lead']->authorizationUser(), 'admin', $folder->authorizationObject());
            $this->grant($users['editor']->authorizationUser(), 'editor', $folder->authorizationObject());
        }
    }

    private function setupDocumentPermissions(Document $document, $users): void
    {
        // Document owner gets owner permission
        $this->grant($document->owner->authorizationUser(), 'owner', $document->authorizationObject());
        
        // Set up collaborative permissions based on document type
        if (str_contains($document->title, 'Handbook') || str_contains($document->title, 'Guidelines')) {
            // Company-wide documents - broader access
            foreach (['manager', 'lead', 'editor', 'viewer'] as $role) {
                $this->grant($users[$role]->authorizationUser(), 'viewer', $document->authorizationObject());
            }
            
            // Managers can edit company documents
            $this->grant($users['manager']->authorizationUser(), 'editor', $document->authorizationObject());
            
        } elseif (str_contains($document->title, 'Project Plan')) {
            // Team documents - team member access
            $this->grant($users['lead']->authorizationUser(), 'editor', $document->authorizationObject());
            $this->grant($users['editor']->authorizationUser(), 'editor', $document->authorizationObject());
            $this->grant($users['viewer']->authorizationUser(), 'viewer', $document->authorizationObject());
            
        } else {
            // Regular documents - limited sharing
            $this->grant($users['editor']->authorizationUser(), 'editor', $document->authorizationObject());
            $this->grant($users['viewer']->authorizationUser(), 'viewer', $document->authorizationObject());
        }

        if ($this->command && rand(1, 5) === 1) { // Show progress for some documents
            $this->command->info("  ✓ Document: {$document->title}");
        }
    }

    /**
     * Write permissions in batch (wrapper for grantMany).
     *
     * @param array $permissions Array of permission tuples
     * @return void
     */
    protected function writePermissions(array $permissions): void
    {
        $this->grantMany($permissions);
    }
}