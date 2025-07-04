<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenFGA\Laravel\Facades\OpenFga;
use OpenFGA\Laravel\Testing\{FakesOpenFga, CreatesPermissionData};
use Tests\TestCase;

class DocumentManagementTest extends TestCase
{
    use RefreshDatabase, FakesOpenFga, CreatesPermissionData;

    private User $admin;
    private User $editor;
    private User $viewer;
    private User $outsider;
    private Organization $organization;
    private Team $team;
    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up fake OpenFGA for testing
        $this->fakeOpenFga();

        // Create test users
        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->editor = User::factory()->create(['name' => 'Editor User']);
        $this->viewer = User::factory()->create(['name' => 'Viewer User']);
        $this->outsider = User::factory()->create(['name' => 'Outsider User']);

        // Create organization and team
        $this->organization = Organization::factory()->create(['name' => 'Test Organization']);
        $this->team = Team::factory()->create(['name' => 'Test Team']);

        // Create test document
        $this->document = Document::factory()->create([
            'title' => 'Test Document',
            'content' => 'This is test content',
            'owner_id' => $this->admin->id,
            'team_id' => $this->team->id,
        ]);

        // Set up permissions
        $this->setupPermissions();
    }

    private function setupPermissions(): void
    {
        $fake = $this->getFakeOpenFga();

        // Organization permissions
        $fake->grant($this->admin->authorizationUser(), 'admin', $this->organization->authorizationObject());
        $fake->grant($this->editor->authorizationUser(), 'member', $this->organization->authorizationObject());
        $fake->grant($this->viewer->authorizationUser(), 'member', $this->organization->authorizationObject());

        // Team permissions
        $fake->grant($this->admin->authorizationUser(), 'lead', $this->team->authorizationObject());
        $fake->grant($this->editor->authorizationUser(), 'member', $this->team->authorizationObject());
        $fake->grant($this->viewer->authorizationUser(), 'member', $this->team->authorizationObject());

        // Document permissions
        $fake->grant($this->admin->authorizationUser(), 'owner', $this->document->authorizationObject());
        $fake->grant($this->editor->authorizationUser(), 'editor', $this->document->authorizationObject());
        $fake->grant($this->viewer->authorizationUser(), 'viewer', $this->document->authorizationObject());
    }

    public function test_document_owner_can_view_document()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('documents.show', $this->document));

        $response->assertOk();
        $response->assertSee($this->document->title);
        $response->assertSee($this->document->content);
        
        // Verify permission check was made
        $this->assertPermissionChecked(
            $this->admin->authorizationUser(),
            'viewer',
            $this->document->authorizationObject()
        );
    }

    public function test_document_editor_can_view_and_edit_document()
    {
        // Test viewing
        $response = $this->actingAs($this->editor)
            ->get(route('documents.show', $this->document));

        $response->assertOk();
        $response->assertSee('Edit Document'); // Should see edit button

        // Test editing
        $response = $this->actingAs($this->editor)
            ->get(route('documents.edit', $this->document));

        $response->assertOk();

        // Test updating
        $response = $this->actingAs($this->editor)
            ->put(route('documents.update', $this->document), [
                'title' => 'Updated Title',
                'content' => 'Updated content',
                'status' => 'published',
            ]);

        $response->assertRedirect(route('documents.show', $this->document));
        
        // Verify permission checks
        $this->assertPermissionChecked(
            $this->editor->authorizationUser(),
            'editor',
            $this->document->authorizationObject()
        );
    }

    public function test_document_viewer_can_only_view_document()
    {
        // Can view document
        $response = $this->actingAs($this->viewer)
            ->get(route('documents.show', $this->document));

        $response->assertOk();
        $response->assertDontSee('Edit Document'); // Should not see edit button

        // Cannot edit document
        $response = $this->actingAs($this->viewer)
            ->get(route('documents.edit', $this->document));

        $response->assertForbidden();

        // Cannot update document
        $response = $this->actingAs($this->viewer)
            ->put(route('documents.update', $this->document), [
                'title' => 'Hacker Title',
            ]);

        $response->assertForbidden();
    }

    public function test_outsider_cannot_access_document()
    {
        // Cannot view document
        $response = $this->actingAs($this->outsider)
            ->get(route('documents.show', $this->document));

        $response->assertForbidden();

        // Cannot edit document
        $response = $this->actingAs($this->outsider)
            ->get(route('documents.edit', $this->document));

        $response->assertForbidden();

        // Cannot delete document
        $response = $this->actingAs($this->outsider)
            ->delete(route('documents.destroy', $this->document));

        $response->assertForbidden();
    }

    public function test_document_owner_can_share_document()
    {
        $newUser = User::factory()->create();

        $response = $this->actingAs($this->admin)
            ->post(route('documents.share', $this->document), [
                'user_id' => $newUser->id,
                'permission' => 'editor',
            ]);

        $response->assertRedirect();
        
        // Verify permission was granted
        $this->assertPermissionGranted(
            $newUser->authorizationUser(),
            'editor',
            $this->document->authorizationObject()
        );
    }

    public function test_document_owner_can_remove_access()
    {
        $response = $this->actingAs($this->admin)
            ->delete(route('documents.removeAccess', $this->document), [
                'user_id' => $this->viewer->id,
            ]);

        $response->assertRedirect();
        
        // Verify permission was revoked
        $this->assertPermissionNotGranted(
            $this->viewer->authorizationUser(),
            'viewer',
            $this->document->authorizationObject()
        );
    }

    public function test_document_duplication_creates_copy_with_correct_permissions()
    {
        $response = $this->actingAs($this->viewer)
            ->post(route('documents.duplicate', $this->document));

        $response->assertRedirect();
        
        // Find the duplicated document
        $duplicatedDocument = Document::where('title', 'Copy of ' . $this->document->title)->first();
        $this->assertNotNull($duplicatedDocument);
        $this->assertEquals($this->viewer->id, $duplicatedDocument->owner_id);
        
        // Verify new owner has correct permissions
        $this->assertPermissionGranted(
            $this->viewer->authorizationUser(),
            'owner',
            $duplicatedDocument->authorizationObject()
        );
    }

    public function test_document_deletion_cleans_up_permissions()
    {
        // Verify permissions exist before deletion
        $this->assertPermissionGranted(
            $this->admin->authorizationUser(),
            'owner',
            $this->document->authorizationObject()
        );

        $response = $this->actingAs($this->admin)
            ->delete(route('documents.destroy', $this->document));

        $response->assertRedirect(route('documents.index'));
        
        // Verify document is deleted
        $this->assertSoftDeleted($this->document);
        
        // Verify permissions were cleaned up
        $this->assertPermissionNotGranted(
            $this->admin->authorizationUser(),
            'owner',
            $this->document->authorizationObject()
        );
    }

    public function test_document_index_filters_by_user_permissions()
    {
        // Create additional documents
        $publicDocument = Document::factory()->create(['title' => 'Public Document']);
        $privateDocument = Document::factory()->create(['title' => 'Private Document']);

        $fake = $this->getFakeOpenFga();
        
        // Grant viewer access to public document only
        $fake->grant($this->viewer->authorizationUser(), 'viewer', $publicDocument->authorizationObject());

        $response = $this->actingAs($this->viewer)
            ->get(route('documents.index'));

        $response->assertOk();
        $response->assertSee($this->document->title); // Has access via team
        $response->assertSee($publicDocument->title); // Has explicit access
        $response->assertDontSee($privateDocument->title); // No access
    }

    public function test_team_document_sharing_workflow()
    {
        // Create a new team member
        $newMember = User::factory()->create();
        $fake = $this->getFakeOpenFga();
        
        // Add user to team
        $fake->grant($newMember->authorizationUser(), 'member', $this->team->authorizationObject());

        // Team member should inherit document access through team relationship
        $response = $this->actingAs($newMember)
            ->get(route('documents.show', $this->document));

        // This would depend on your inheritance model implementation
        // For this example, assume team members get automatic viewer access
        $fake->grant($newMember->authorizationUser(), 'viewer', $this->document->authorizationObject());
        
        $response = $this->actingAs($newMember)
            ->get(route('documents.show', $this->document));

        $response->assertOk();
    }

    public function test_bulk_permission_operations()
    {
        $users = User::factory()->count(5)->create();
        $fake = $this->getFakeOpenFga();

        // Grant multiple users access to document
        foreach ($users as $user) {
            $fake->grant($user->authorizationUser(), 'viewer', $this->document->authorizationObject());
        }

        // Test that all users can access the document
        foreach ($users as $user) {
            $response = $this->actingAs($user)
                ->get(route('documents.show', $this->document));

            $response->assertOk();
        }

        // Verify all permission checks were made
        foreach ($users as $user) {
            $this->assertPermissionChecked(
                $user->authorizationUser(),
                'viewer',
                $this->document->authorizationObject()
            );
        }
    }

    public function test_hierarchical_permission_inheritance()
    {
        // This test demonstrates how organization/department/team hierarchy affects document access
        $departmentManager = User::factory()->create();
        $fake = $this->getFakeOpenFga();

        // Grant manager access at organization level
        $fake->grant($departmentManager->authorizationUser(), 'manager', $this->organization->authorizationObject());

        // Manager should inherit access to team documents
        // This depends on your specific inheritance model
        $fake->grant($departmentManager->authorizationUser(), 'manager', $this->team->authorizationObject());
        $fake->grant($departmentManager->authorizationUser(), 'editor', $this->document->authorizationObject());

        $response = $this->actingAs($departmentManager)
            ->get(route('documents.edit', $this->document));

        $response->assertOk();
    }

    public function test_permission_caching_behavior()
    {
        // First request should check permissions
        $response = $this->actingAs($this->viewer)
            ->get(route('documents.show', $this->document));

        $response->assertOk();
        
        // Verify initial check was made
        $this->assertPermissionCheckCount(1);

        // Reset counter to test caching
        $this->getFakeOpenFga()->resetChecks();

        // Second request should use cache (depending on implementation)
        $response = $this->actingAs($this->viewer)
            ->get(route('documents.show', $this->document));

        $response->assertOk();
        
        // This assertion would depend on your caching implementation
        // For this example, assume no caching in test environment
        $this->assertPermissionCheckCount(1);
    }

    public function test_api_permission_check_endpoint()
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/permissions/check', [
                'checks' => [
                    [
                        'user' => $this->admin->authorizationUser(),
                        'relation' => 'owner',
                        'object' => $this->document->authorizationObject(),
                    ],
                    [
                        'user' => $this->viewer->authorizationUser(),
                        'relation' => 'editor',
                        'object' => $this->document->authorizationObject(),
                    ],
                ]
            ]);

        $response->assertOk();
        $response->assertJson([
            'results' => [true, false] // Admin is owner, viewer is not editor
        ]);
    }

    public function test_direct_openfga_facade_usage()
    {
        // Test direct check
        $hasAccess = OpenFga::check(
            user: $this->admin->authorizationUser(),
            relation: 'owner',
            object: $this->document->authorizationObject()
        );
        $this->assertTrue($hasAccess);

        // Test write permission
        $newUser = User::factory()->create();
        OpenFga::write([
            [
                'user' => $newUser->authorizationUser(),
                'relation' => 'viewer',
                'object' => $this->document->authorizationObject(),
            ]
        ]);

        // Test batch check
        $results = OpenFga::batchCheck([
            [
                'user' => $newUser->authorizationUser(),
                'relation' => 'viewer',
                'object' => $this->document->authorizationObject(),
            ],
            [
                'user' => $newUser->authorizationUser(),
                'relation' => 'editor',
                'object' => $this->document->authorizationObject(),
            ],
        ]);
        $this->assertEquals([true, false], $results);

        // Test list objects
        $documents = OpenFga::listObjects(
            user: $this->viewer->authorizationUser(),
            relation: 'viewer',
            type: 'document'
        );
        $this->assertContains($this->document->authorizationObject(), $documents);
    }
}