<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Folder extends Model
{
    use HasFactory, HasAuthorization;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'organization_id',
        'department_id',
        'team_id',
        'parent_id',
    ];

    /**
     * Define the authorization relations for this model.
     */
    protected function authorizationRelations(): array
    {
        return [
            'admin',   // Can manage folder and all contents
            'manager', // Can manage folder contents
            'editor',  // Can add/edit documents in folder
            'viewer',  // Can view folder contents
        ];
    }

    /**
     * The organization this folder belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The department this folder belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The team this folder belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The parent folder.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /**
     * The child folders.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    /**
     * The documents in this folder.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Get the authorization object string for OpenFGA.
     */
    public function authorizationObject(): string
    {
        return 'folder:' . $this->id;
    }

    /**
     * Get the full path of this folder.
     */
    public function getFullPath(): string
    {
        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' / ', $path);
    }
}