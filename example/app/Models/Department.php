<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Department extends Model
{
    use HasFactory, HasAuthorization;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'organization_id',
    ];

    /**
     * Define the authorization relations for this model.
     */
    protected function authorizationRelations(): array
    {
        return [
            'admin',    // Inherited from organization
            'manager',  // Department manager
            'member',   // Department member
        ];
    }

    /**
     * The organization this department belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The teams in this department.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Create a new team in this department.
     */
    public function createTeam(array $attributes, ?User $lead = null): Team
    {
        $team = $this->teams()->create($attributes);

        if ($lead) {
            $team->grant($lead, 'lead');
        }

        return $team;
    }
}