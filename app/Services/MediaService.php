<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaService
{
    public function store(UploadedFile $file, User $user): Media
    {
        $path = $file->store('media/'.now()->format('Y/m'), 'public');

        return Media::create([
            'user_id' => $user->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    public function delete(Media $media): bool
    {
        Storage::disk($media->disk)->delete($media->path);

        return (bool) $media->delete();
    }
}
