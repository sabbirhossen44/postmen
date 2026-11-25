<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Environment extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'name',
        'is_active',
        'variables',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'variables' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
