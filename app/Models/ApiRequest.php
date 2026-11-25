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
        'pre_request_script',
        'test_script',
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

    /**
     * The flat script fields are views onto the stored `scripts` JSON
     * ({ pre_request, tests }) — there are no separate columns for them.
     * The UI already stores scripts this way (see migration
     * 2026_11_25_130001), so adding `pre_request_script` / `test_script`
     * columns would duplicate the same data.
     */
    protected $appends = [
        'pre_request_script',
        'test_script',
    ];

    public function getPreRequestScriptAttribute(): ?string
    {
        $scripts = $this->scripts ?? [];

        return is_array($scripts) ? ($scripts['pre_request'] ?? null) : null;
    }

    public function setPreRequestScriptAttribute(?string $value): void
    {
        $scripts = $this->scripts ?? [];
        if (! is_array($scripts)) {
            $scripts = [];
        }

        if ($value === null || trim($value) === '') {
            unset($scripts['pre_request']);
        } else {
            $scripts['pre_request'] = $value;
        }

        $this->attributes['scripts'] = json_encode($scripts);
    }

    public function getTestScriptAttribute(): ?string
    {
        $scripts = $this->scripts ?? [];

        return is_array($scripts) ? ($scripts['tests'] ?? null) : null;
    }

    public function setTestScriptAttribute(?string $value): void
    {
        $scripts = $this->scripts ?? [];
        if (! is_array($scripts)) {
            $scripts = [];
        }

        if ($value === null || trim($value) === '') {
            unset($scripts['tests']);
        } else {
            $scripts['tests'] = $value;
        }

        $this->attributes['scripts'] = json_encode($scripts);
    }

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
