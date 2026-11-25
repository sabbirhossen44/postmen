<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequest extends Model
{
    use HasFactory;

    protected $table = 'requests';

    protected $fillable = [
        'collection_id',
        'folder_id',
        'user_id',
        'name',
        'method',
        'url',
        'params',
        'headers',
        'auth_type',
        'auth',
        'body_type',
        'body',
        'body_file_media_id',
        'scripts',
        'settings',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'params' => 'array',
        'headers' => 'array',
        'auth' => 'array',
        'body' => 'array',
        'scripts' => 'array',
        'settings' => 'array',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bodyFile(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'body_file_media_id');
    }
}
