<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Organization extends Model
{
    use HasFactory, HasAuthorization;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'settings',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Define the authorization relations for this model.
     */
    protected function authorizationRelations(): array
    {
        return [
            'admin',    // Can manage organization settings and users
            'manager',  // Can manage departments and content
            'member',   // Basic organization access
        ];
    }

    /**
     * The users that belong to this organization.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The departments in this organization.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * The folders directly under this organization.
     */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    /**
     * All documents in this organization (through departments and teams).
     */
    public function documents()
    {
        return Document::where(function ($query) {
            $query->whereHas('folder.organization', function ($q) {
                $q->where('id', $this->id);
            })
            ->orWhereHas('team.department.organization', function ($q) {
                $q->where('id', $this->id);
            });
        });
    }

    /**
     * Get all admins of this organization.
     */
    public function getAdmins()
    {
        return $this->getUsersWithRelation('admin');
    }

    /**
     * Get all members of this organization.
     */
    public function getMembers()
    {
        return $this->getUsersWithRelation('member');
    }

    /**
     * Add a user to the organization with a specific role.
     */
    public function addUser(User $user, string $role = 'member'): void
    {
        // Add to database relationship
        $this->users()->syncWithoutDetaching([
            $user->id => ['role' => $role, 'joined_at' => now()]
        ]);

        // Grant OpenFGA permission
        $this->grant($user, $role);

        // If admin, also grant manager and member
        if ($role === 'admin') {
            $this->grant($user, 'manager');
            $this->grant($user, 'member');
        }

        // If manager, also grant member
        if ($role === 'manager') {
            $this->grant($user, 'member');
        }
    }

    /**
     * Remove a user from the organization.
     */
    public function removeUser(User $user): void
    {
        // Remove from database
        $this->users()->detach($user->id);

        // Revoke all OpenFGA permissions
        foreach ($this->authorizationRelations() as $relation) {
            $this->revoke($user, $relation);
        }
    }

    /**
     * Create a new department in this organization.
     */
    public function createDepartment(array $attributes, ?User $manager = null): Department
    {
        $department = $this->departments()->create($attributes);

        if ($manager) {
            $department->grant($manager, 'manager');
        }

        return $department;
    }

    /**
     * Get organization statistics.
     */
    public function getStats(): array
    {
        return [
            'users_count' => $this->users()->count(),
            'departments_count' => $this->departments()->count(),
            'teams_count' => $this->departments()->withCount('teams')->get()->sum('teams_count'),
            'documents_count' => $this->documents()->count(),
            'admins_count' => $this->users()->wherePivot('role', 'admin')->count(),
        ];
    }

    /**
     * Get the authorization object string for OpenFGA.
     */
    public function authorizationObject(): string
    {
        return 'organization:' . $this->id;
    }

    /**
     * Scope to get organizations where user has specific permission.
     */
    public function scopeWhereUserCan($query, User $user, string $relation)
    {
        $accessibleOrgs = collect($this->listObjects($user->authorizationUser(), $relation, 'organization'))
            ->map(fn($obj) => (int) str_replace('organization:', '', $obj))
            ->filter()
            ->toArray();

        return $query->whereIn('id', $accessibleOrgs);
    }
}