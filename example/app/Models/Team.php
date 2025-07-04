<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Team extends Model
{
    use HasFactory, HasAuthorization;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'department_id',
    ];

    /**
     * Define the authorization relations for this model.
     */
    protected function authorizationRelations(): array
    {
        return [
            'admin',   // Inherited from organization/department
            'manager', // Inherited from department
            'lead',    // Team lead
            'member',  // Team member
        ];
    }

    /**
     * The department this team belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The users that belong to this team.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The documents associated with this team.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * The folders associated with this team.
     */
    public function folders(): MorphMany
    {
        return $this->morphMany(Folder::class, 'folderable');
    }

    /**
     * Get the authorization object string for OpenFGA.
     */
    public function authorizationObject(): string
    {
        return 'team:' . $this->id;
    }

    /**
     * Add a user to the team with a specific role.
     */
    public function addMember(User $user, string $role = 'member'): void
    {
        // Add to database relationship
        $this->users()->syncWithoutDetaching([
            $user->id => ['role' => $role, 'joined_at' => now()]
        ]);

        // Grant OpenFGA permission
        $this->grant($user, $role);

        // If lead, also grant member
        if ($role === 'lead') {
            $this->grant($user, 'member');
        }
    }
}