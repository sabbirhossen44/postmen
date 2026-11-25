<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlobalVariable extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'key',
        'initial_value',
        'current_value',
        'type',
    ];

    protected $casts = [
        'type' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Effective value: current wins, initial is the fallback. */
    public function effectiveValue(): ?string
    {
        return $this->current_value !== null && $this->current_value !== ''
            ? $this->current_value
            : $this->initial_value;
    }

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * Array for API responses. Secret current values are never exposed
     * unless explicitly requested (edit fetch with $reveal = true).
     */
    public function toDisplayArray(bool $reveal = false): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'initial_value' => $this->isSecret() && ! $reveal ? null : $this->initial_value,
            'current_value' => $this->isSecret() && ! $reveal ? null : $this->current_value,
            'type' => $this->type,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
