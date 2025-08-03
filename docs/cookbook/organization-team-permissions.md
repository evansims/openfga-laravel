# Handling Organization/Team Permissions

This recipe demonstrates how to implement complex organizational structures with teams, departments, and nested permissions. This pattern is common in enterprise applications where access control needs to reflect organizational hierarchies.

## Authorization Model

```dsl
model
  schema 1.1

type user

type organization
  relations
    define admin: [user]
    define member: [user] or admin

type team
  relations
    define organization: [organization]
    define admin: [user]
    define member: [user] or admin
    define parent: [team]
    define member_including_parent: member or parent#member_including_parent

type document
  relations
    define organization: [organization]
    define team: [team]
    define owner: [user]
    define admin: [user] or owner or organization#admin or team#admin
    define editor: [user] or admin or team#member
    define viewer: [user] or editor or organization#member
```

This model supports:
- **Organizations** with admins and members
- **Teams** within organizations with hierarchical relationships
- **Documents** that can belong to organizations and teams
- **Inherited permissions** from organization/team membership

## Organization Management

### 1. Creating Organizations

```php
namespace App\Services;

use OpenFGA\Laravel\Facades\OpenFga;

class OrganizationService
{
    public function createOrganization(string $name, string $adminUserId): string
    {
        $organizationId = "organization:" . \Str::slug($name);

        // Make the creator an admin
        OpenFga::grant("user:{$adminUserId}", 'admin', $organizationId);

        return $organizationId;
    }

    public function addMember(string $organizationId, string $userId, string $role = 'member'): bool
    {
        return OpenFga::grant("user:{$userId}", $role, $organizationId);
    }

    public function removeMember(string $organizationId, string $userId, string $role = 'member'): bool
    {
        return OpenFga::revoke("user:{$userId}", $role, $organizationId);
    }

    public function getMembers(string $organizationId): array
    {
        return [
            'admins' => OpenFga::listUsers($organizationId, 'admin'),
            'members' => OpenFga::listUsers($organizationId, 'member'),
        ];
    }

    public function transferOwnership(string $organizationId, string $currentAdminId, string $newAdminId): bool
    {
        return OpenFga::writeBatch(
            writes: [
                ['user:' . $newAdminId, 'admin', $organizationId],
            ],
            deletes: [
                ['user:' . $currentAdminId, 'admin', $organizationId],
            ]
        );
    }
}
```

### 2. Team Management

```php
class TeamService
{
    public function createTeam(string $name, string $organizationId, string $adminUserId, ?string $parentTeamId = null): string
    {
        $teamId = "team:" . \Str::slug($name);

        $writes = [
            // Set team admin
            ['user:' . $adminUserId, 'admin', $teamId],
            // Associate with organization
            [$organizationId, 'organization', $teamId],
        ];

        // Set parent team if provided
        if ($parentTeamId) {
            $writes[] = [$parentTeamId, 'parent', $teamId];
        }

        OpenFga::writeBatch($writes);

        return $teamId;
    }

    public function addTeamMember(string $teamId, string $userId, string $role = 'member'): bool
    {
        return OpenFga::grant("user:{$userId}", $role, $teamId);
    }

    public function createSubTeam(string $parentTeamId, string $name, string $adminUserId): string
    {
        $subTeamId = "team:" . \Str::slug($name);

        OpenFga::writeBatch([
            ['user:' . $adminUserId, 'admin', $subTeamId],
            [$parentTeamId, 'parent', $subTeamId],
        ]);

        return $subTeamId;
    }

    public function getTeamHierarchy(string $teamId): array
    {
        $children = OpenFga::listObjects($teamId, 'parent', 'team');
        $parent = OpenFga::listObjects($teamId, 'parent', 'team', inverse: true);

        return [
            'team_id' => $teamId,
            'parent' => $parent[0] ?? null,
            'children' => $children,
            'members' => $this->getTeamMembers($teamId),
        ];
    }

    public function getTeamMembers(string $teamId): array
    {
        return [
            'admins' => OpenFga::listUsers($teamId, 'admin'),
            'members' => OpenFga::listUsers($teamId, 'member'),
            'all_members' => OpenFga::listUsers($teamId, 'member_including_parent'),
        ];
    }
}
```

## Document Management

### 1. Document Service

```php
class DocumentService
{
    public function createDocument(array $data, string $ownerId, ?string $organizationId = null, ?string $teamId = null): Document
    {
        $document = Document::create($data);

        $writes = [
            ['user:' . $ownerId, 'owner', "document:{$document->id}"],
        ];

        if ($organizationId) {
            $writes[] = [$organizationId, 'organization', "document:{$document->id}"];
        }

        if ($teamId) {
            $writes[] = [$teamId, 'team', "document:{$document->id}"];
        }

        OpenFga::writeBatch($writes);

        return $document;
    }

    public function shareWithTeam(string $documentId, string $teamId, string $permission = 'viewer'): bool
    {
        return OpenFga::grant($teamId, $permission, "document:{$documentId}");
    }

    public function shareWithOrganization(string $documentId, string $organizationId, string $permission = 'viewer'): bool
    {
        return OpenFga::grant($organizationId, $permission, "document:{$documentId}");
    }

    public function getDocumentAccess(string $documentId): array
    {
        return [
            'owners' => OpenFga::listUsers("document:{$documentId}", 'owner'),
            'admins' => OpenFga::listUsers("document:{$documentId}", 'admin'),
            'editors' => OpenFga::listUsers("document:{$documentId}", 'editor'),
            'viewers' => OpenFga::listUsers("document:{$documentId}", 'viewer'),
            'teams' => OpenFga::listObjects("document:{$documentId}", 'team', 'team', inverse: true),
            'organizations' => OpenFga::listObjects("document:{$documentId}", 'organization', 'organization', inverse: true),
        ];
    }
}
```

### 2. Permission Queries

```php
class PermissionQueryService
{
    public function getUserAccessibleDocuments(string $userId, string $permission = 'viewer'): array
    {
        return OpenFga::listObjects("user:{$userId}", $permission, 'document');
    }

    public function getTeamDocuments(string $teamId, string $permission = 'viewer'): array
    {
        return OpenFga::listObjects($teamId, $permission, 'document');
    }

    public function getOrganizationDocuments(string $organizationId, string $permission = 'viewer'): array
    {
        return OpenFga::listObjects($organizationId, $permission, 'document');
    }

    public function canUserAccessDocument(string $userId, string $documentId, string $permission = 'viewer'): bool
    {
        return OpenFga::check("user:{$userId}", $permission, "document:{$documentId}");
    }

    public function getUserPermissionsOnDocument(string $userId, string $documentId): array
    {
        $permissions = [];

        foreach (['owner', 'admin', 'editor', 'viewer'] as $permission) {
            if (OpenFga::check("user:{$userId}", $permission, "document:{$documentId}")) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }
}
```

## Eloquent Integration

### 1. Organization Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenFGA\Laravel\Facades\OpenFga;

class Organization extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function addMember(User $user, string $role = 'member'): bool
    {
        return OpenFga::grant("user:{$user->id}", $role, "organization:{$this->id}");
    }

    public function removeMember(User $user, string $role = 'member'): bool
    {
        return OpenFga::revoke("user:{$user->id}", $role, "organization:{$this->id}");
    }

    public function isMember(User $user): bool
    {
        return OpenFga::check("user:{$user->id}", 'member', "organization:{$this->id}");
    }

    public function isAdmin(User $user): bool
    {
        return OpenFga::check("user:{$user->id}", 'admin', "organization:{$this->id}");
    }

    public function getMembers(): array
    {
        return [
            'admins' => OpenFga::listUsers("organization:{$this->id}", 'admin'),
            'members' => OpenFga::listUsers("organization:{$this->id}", 'member'),
        ];
    }
}
```

### 2. Team Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenFGA\Laravel\Facades\OpenFga;

class Team extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'organization_id', 'parent_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Team::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function addMember(User $user, string $role = 'member'): bool
    {
        return OpenFga::grant("user:{$user->id}", $role, "team:{$this->id}");
    }

    public function removeMember(User $user, string $role = 'member'): bool
    {
        return OpenFga::revoke("user:{$user->id}", $role, "team:{$this->id}");
    }

    public function isMember(User $user, bool $includeParent = false): bool
    {
        $relation = $includeParent ? 'member_including_parent' : 'member';
        return OpenFga::check("user:{$user->id}", $relation, "team:{$this->id}");
    }

    public function isAdmin(User $user): bool
    {
        return OpenFga::check("user:{$user->id}", 'admin', "team:{$this->id}");
    }

    public function getMembers(bool $includeParent = false): array
    {
        $relation = $includeParent ? 'member_including_parent' : 'member';

        return [
            'admins' => OpenFga::listUsers("team:{$this->id}", 'admin'),
            'members' => OpenFga::listUsers("team:{$this->id}", $relation),
        ];
    }
}
```

### 3. Enhanced Document Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Document extends Model
{
    use HasAuthorization;

    protected $fillable = ['title', 'content', 'organization_id', 'team_id', 'user_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function authorizationType(): string
    {
        return 'document';
    }

    public function shareWithTeam(Team $team, string $permission = 'viewer'): bool
    {
        return $this->grant("team:{$team->id}", $permission);
    }

    public function shareWithOrganization(Organization $organization, string $permission = 'viewer'): bool
    {
        return $this->grant("organization:{$organization->id}", $permission);
    }

    public function getTeamAccess(): array
    {
        return OpenFga::listObjects($this->authorizationObject(), 'team', 'team', inverse: true);
    }

    public function getOrganizationAccess(): array
    {
        return OpenFga::listObjects($this->authorizationObject(), 'organization', 'organization', inverse: true);
    }
}
```

## Advanced Patterns

### 1. Cross-Organization Collaboration

```php
class CollaborationService
{
    public function createCrossOrgProject(array $organizationIds, string $projectName, string $creatorId): string
    {
        $projectId = "project:" . \Str::slug($projectName);

        $writes = [
            ['user:' . $creatorId, 'admin', $projectId],
        ];

        // Add all organizations as members
        foreach ($organizationIds as $orgId) {
            $writes[] = [$orgId, 'member', $projectId];
        }

        OpenFga::writeBatch($writes);

        return $projectId;
    }

    public function inviteExternalUser(string $projectId, string $email, string $role = 'viewer'): bool
    {
        // Create temporary user for external invite
        $tempUserId = "temp-user:" . md5($email);

        return OpenFga::grant($tempUserId, $role, $projectId);
    }
}
```

### 2. Department-Based Access Control

```php
class DepartmentService
{
    public function setupDepartmentStructure(string $organizationId): void
    {
        $departments = [
            'engineering' => ['backend-team', 'frontend-team', 'devops-team'],
            'marketing' => ['content-team', 'social-media-team', 'analytics-team'],
            'sales' => ['inside-sales-team', 'field-sales-team', 'sales-ops-team'],
        ];

        foreach ($departments as $deptName => $teams) {
            $deptId = "team:{$deptName}";

            // Create department
            OpenFga::grant($organizationId, 'organization', $deptId);

            // Create teams under department
            foreach ($teams as $teamName) {
                $teamId = "team:{$teamName}";
                OpenFga::writeBatch([
                    [$organizationId, 'organization', $teamId],
                    [$deptId, 'parent', $teamId],
                ]);
            }
        }
    }

    public function getDepartmentMembers(string $departmentId): array
    {
        return OpenFga::listUsers($departmentId, 'member_including_parent');
    }
}
```

### 3. Conditional Access Based on Context

```php
class ContextualAccessService
{
    public function checkConditionalAccess(string $userId, string $documentId, array $context = []): bool
    {
        // Basic permission check
        if (!OpenFga::check("user:{$userId}", 'viewer', "document:{$documentId}")) {
            return false;
        }

        // Location-based access
        if (isset($context['location'])) {
            if (!$this->isLocationAllowed($userId, $context['location'])) {
                return false;
            }
        }

        // Time-based access
        if (isset($context['time_restriction'])) {
            if (!$this->isTimeAllowed($userId, $context['time_restriction'])) {
                return false;
            }
        }

        // Project-based access
        if (isset($context['project'])) {
            if (!$this->isProjectMember($userId, $context['project'])) {
                return false;
            }
        }

        return true;
    }

    private function isLocationAllowed(string $userId, string $location): bool
    {
        // Check if user is in allowed location
        $allowedLocations = OpenFga::listObjects("user:{$userId}", 'allowed_location', 'location');
        return in_array($location, $allowedLocations);
    }

    private function isTimeAllowed(string $userId, string $timeRestriction): bool
    {
        // Check business hours, etc.
        return match($timeRestriction) {
            'business_hours' => now()->between('09:00', '17:00'),
            'extended_hours' => now()->between('07:00', '22:00'),
            'always' => true,
            default => false,
        };
    }

    private function isProjectMember(string $userId, string $projectId): bool
    {
        return OpenFga::check("user:{$userId}", 'member', "project:{$projectId}");
    }
}
```

## API Endpoints

### 1. Organization Management API

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationService $organizationService)
    {
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $organization = Organization::create($request->validated());

        // Set up OpenFGA permissions
        $this->organizationService->createOrganization(
            $organization->name,
            $request->user()->id
        );

        return response()->json($organization, 201);
    }

    public function addMember(Request $request, Organization $organization)
    {
        $this->authorize('admin', $organization);

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:admin,member',
        ]);

        $success = $this->organizationService->addMember(
            "organization:{$organization->id}",
            $request->user_id,
            $request->role
        );

        return response()->json(['success' => $success]);
    }

    public function members(Organization $organization)
    {
        $this->authorize('member', $organization);

        $members = $this->organizationService->getMembers("organization:{$organization->id}");

        return response()->json($members);
    }
}
```

### 2. Team Management API

```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\TeamService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(private TeamService $teamService)
    {
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'organization_id' => 'required|exists:organizations,id',
            'parent_id' => 'nullable|exists:teams,id',
        ]);

        $this->authorize('admin', Organization::find($request->organization_id));

        $team = Team::create($request->validated());

        // Set up OpenFGA permissions
        $this->teamService->createTeam(
            $team->name,
            "organization:{$team->organization_id}",
            $request->user()->id,
            $request->parent_id ? "team:{$request->parent_id}" : null
        );

        return response()->json($team, 201);
    }

    public function hierarchy(Team $team)
    {
        $this->authorize('member', $team);

        $hierarchy = $this->teamService->getTeamHierarchy("team:{$team->id}");

        return response()->json($hierarchy);
    }
}
```

## Testing

```php
use OpenFGA\Laravel\Testing\FakesOpenFga;
use Tests\TestCase;

class OrganizationTeamTest extends TestCase
{
    use FakesOpenFga;

    public function test_organization_member_can_access_team_documents()
    {
        $this->fakeOpenFga();

        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $team = Team::factory()->create(['organization_id' => $organization->id]);
        $document = Document::factory()->create(['team_id' => $team->id]);

        // Set up permissions
        OpenFga::grant("user:{$user->id}", 'member', "organization:{$organization->id}");
        OpenFga::grant("organization:{$organization->id}", 'organization', "team:{$team->id}");
        OpenFga::grant("team:{$team->id}", 'team', "document:{$document->id}");

        // Test access
        $this->assertTrue(
            OpenFga::check("user:{$user->id}", 'viewer', "document:{$document->id}")
        );

        OpenFga::assertChecked("user:{$user->id}", 'viewer', "document:{$document->id}");
    }

    public function test_team_hierarchy_permissions()
    {
        $this->fakeOpenFga();

        $user = User::factory()->create();
        $parentTeam = Team::factory()->create();
        $childTeam = Team::factory()->create();

        // Set up hierarchy
        OpenFga::grant("user:{$user->id}", 'member', "team:{$parentTeam->id}");
        OpenFga::grant("team:{$parentTeam->id}", 'parent', "team:{$childTeam->id}");

        // User should be member of child team through parent
        $this->assertTrue(
            OpenFga::check("user:{$user->id}", 'member_including_parent', "team:{$childTeam->id}")
        );
    }
}
```

This comprehensive approach to organization and team permissions provides a flexible foundation that can be adapted to various organizational structures while maintaining security and performance.
