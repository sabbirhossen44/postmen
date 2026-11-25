<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunResult extends Model
{
    use HasFactory;

    /**
     * `status` is the outcome: passed | failed | error.
     * `status_code` is the HTTP status the request returned (null when the
     * request never got a response). The Runner UI reads `status` as the
     * HTTP code, so RunController presents `status_code` under that key.
     */
    protected $fillable = [
        'run_id',
        'request_id',
        'iteration',
        'status',
        'status_code',
        'time_ms',
        'size',
        'test_results',
        'error',
    ];

    protected $casts = [
        'test_results' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApiRequest::class, 'request_id');
    }
}
