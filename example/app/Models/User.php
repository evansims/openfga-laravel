<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use OpenFGA\Laravel\Contracts\AuthorizableUser;
use OpenFGA\Laravel\Traits\HasAuthorization;

class User extends Authenticatable implements AuthorizableUser
{
    use HasFactory, Notifiable, HasAuthorization;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's authorization identifier for OpenFGA.
     */
    public function authorizationUser(): string
    {
        return "user:{$this->id}";
    }

    /**
     * The organizations this user belongs to.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The teams this user belongs to.
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Documents owned by this user.
     */
    public function ownedDocuments()
    {
        return $this->hasMany(Document::class, 'owner_id');
    }

    /**
     * Check if user is admin of any organization.
     */
    public function isOrganizationAdmin(): bool
    {
        return $this->organizations()
            ->wherePivot('role', 'admin')
            ->exists();
    }

    /**
     * Check if user is member of a specific organization.
     */
    public function isMemberOf(Organization $organization): bool
    {
        return $this->check($this, 'member', $organization);
    }

    /**
     * Get all documents this user can access with a specific permission.
     */
    public function getAccessibleDocuments(string $permission = 'viewer')
    {
        return Document::whereUserCan($this, $permission)->get();
    }

    /**
     * Grant user a role in an organization.
     */
    public function grantOrganizationRole(Organization $organization, string $role): void
    {
        // Update database relationship
        $this->organizations()->syncWithoutDetaching([
            $organization->id => ['role' => $role, 'joined_at' => now()]
        ]);

        // Grant OpenFGA permission
        $organization->grant($this, $role);
    }

    /**
     * Grant user a role in a team.
     */
    public function grantTeamRole(Team $team, string $role): void
    {
        // Update database relationship
        $this->teams()->syncWithoutDetaching([
            $team->id => ['role' => $role, 'joined_at' => now()]
        ]);

        // Grant OpenFGA permission
        $team->grant($this, $role);
    }
}