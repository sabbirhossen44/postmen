<?php

namespace App\Models;

use App\Services\VariableResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Environment extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'created_by',
        'updated_by',
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

    /**
     * Variables for API responses. Secret current values are hidden
     * unless $reveal is true (explicit edit fetch by the owner).
     */
    public function maskedVariables(bool $reveal = false): array
    {
        return VariableResolver::maskVariableList($this->variables ?? [], $reveal);
    }

    /** Serialised form with secrets masked, for list/show responses. */
    public function toDisplayArray(bool $reveal = false): array
    {
        return [
            ...$this->toArray(),
            'variables' => $this->maskedVariables($reveal),
        ];
    }
}
