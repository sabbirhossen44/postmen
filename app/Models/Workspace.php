<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    public const TYPES = ['personal', 'team'];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'description',
        'media_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /** Team members (the owner is not stored here — see WorkspaceAccess). */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function memberUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['role', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(WorkspaceActivity::class);
    }

    public function isTeam(): bool
    {
        return $this->type === 'team';
    }
}
