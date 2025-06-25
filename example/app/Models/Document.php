<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OpenFGA\Laravel\Traits\HasAuthorization;

class Document extends Model
{
    use HasFactory, HasAuthorization, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'content',
        'excerpt',
        'status',
        'owner_id',
        'folder_id',
        'team_id',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Define the authorization relations for this model.
     */
    protected function authorizationRelations(): array
    {
        return [
            'owner',   // Full control - can delete, share, modify permissions
            'editor',  // Can edit content and metadata
            'viewer',  // Can read content
        ];
    }

    /**
     * The user who owns this document.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The folder this document belongs to.
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * The team this document belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the organization this document belongs to.
     */
    public function getOrganization(): ?Organization
    {
        if ($this->folder) {
            return $this->folder->organization;
        }

        if ($this->team) {
            return $this->team->department->organization;
        }

        return null;
    }

    /**
     * Boot the model and set up event listeners.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically grant owner permission when document is created
        static::created(function (Document $document) {
            if ($document->owner_id) {
                $document->grant($document->owner, 'owner');
                
                // Owner should also have editor and viewer permissions
                $document->grant($document->owner, 'editor');
                $document->grant($document->owner, 'viewer');
            }
        });

        // Clean up permissions when document is deleted
        static::deleting(function (Document $document) {
            $document->revokeAllPermissions();
        });
    }

    /**
     * Share this document with a user.
     */
    public function shareWith(User $user, string $permission = 'viewer'): void
    {
        $this->grant($user, $permission);

        // If granting editor, also grant viewer
        if ($permission === 'editor') {
            $this->grant($user, 'viewer');
        }

        // If granting owner, also grant editor and viewer
        if ($permission === 'owner') {
            $this->grant($user, 'editor');
            $this->grant($user, 'viewer');
        }
    }

    /**
     * Remove user access to this document.
     */
    public function removeAccess(User $user): void
    {
        foreach ($this->authorizationRelations() as $relation) {
            $this->revoke($user, $relation);
        }
    }

    /**
     * Get all users who can access this document.
     */
    public function getAccessibleUsers(): array
    {
        $users = [];
        
        foreach ($this->authorizationRelations() as $relation) {
            $relationUsers = $this->getUsersWithRelation($relation);
            foreach ($relationUsers as $userObj) {
                $userId = str_replace('user:', '', $userObj);
                if (!isset($users[$userId])) {
                    $users[$userId] = [
                        'user_id' => $userId,
                        'permissions' => []
                    ];
                }
                $users[$userId]['permissions'][] = $relation;
            }
        }

        return array_values($users);
    }

    /**
     * Check if document is published.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Check if document is draft.
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Publish the document.
     */
    public function publish(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /**
     * Create a copy of this document.
     */
    public function duplicate(User $newOwner, ?string $title = null): self
    {
        $copy = $this->replicate();
        $copy->title = $title ?? "Copy of {$this->title}";
        $copy->owner_id = $newOwner->id;
        $copy->status = 'draft';
        $copy->published_at = null;
        $copy->save();

        // Grant permissions to new owner
        $copy->grant($newOwner, 'owner');
        $copy->grant($newOwner, 'editor');
        $copy->grant($newOwner, 'viewer');

        return $copy;
    }

    /**
     * Get document statistics.
     */
    public function getStats(): array
    {
        return [
            'viewers_count' => count($this->getUsersWithRelation('viewer')),
            'editors_count' => count($this->getUsersWithRelation('editor')),
            'owners_count' => count($this->getUsersWithRelation('owner')),
            'word_count' => str_word_count(strip_tags($this->content ?? '')),
            'character_count' => strlen(strip_tags($this->content ?? '')),
        ];
    }

    /**
     * Scope to get published documents.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope to get draft documents.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to get documents by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}