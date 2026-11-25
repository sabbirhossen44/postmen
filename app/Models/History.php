<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class History extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'request_id',
        'method',
        'url',
        'status',
        'time_ms',
        'size',
        'response_headers',
        'response_body',
        'error',
        'request_snapshot',
    ];

    protected $casts = [
        'response_headers' => 'array',
        'request_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApiRequest::class, 'request_id');
    }
}
