<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoredCookie extends Model
{
    use HasFactory;

    protected $table = 'cookies';

    protected $fillable = [
        'user_id',
        'workspace_id',
        'domain',
        'name',
        'value',
        'path',
        'key_hash',
        'expires_at',
    ];

    /**
     * Identity hash for one cookie: user + workspace + domain + name + path.
     * Stored so the unique index stays short on MySQL (see the migration).
     */
    public static function keyHash(int $userId, int $workspaceId, string $domain, string $name, string $path): string
    {
        return hash('sha256', implode('|', [$userId, $workspaceId, $domain, $name, $path]));
    }

    protected static function booted(): void
    {
        static::creating(function (StoredCookie $cookie) {
            $cookie->key_hash ??= static::keyHash(
                $cookie->user_id,
                $cookie->workspace_id,
                $cookie->domain,
                $cookie->name,
                $cookie->path ?? '/',
            );
        });
    }

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
