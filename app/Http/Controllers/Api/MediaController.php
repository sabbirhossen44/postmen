<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Models\Media;
use App\Repositories\MediaRepository;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;

class MediaController extends Controller
{
    public function store(UploadMediaRequest $request, MediaService $mediaService): JsonResponse
    {
        $media = $mediaService->store($request->file('file'), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'File uploaded.',
            'data' => [...$media->toArray(), 'url' => $media->url()],
        ], 201);
    }

    public function show(Media $media): JsonResponse
    {
        $this->ensureOwner($media);

        return response()->json([
            'success' => true,
            'message' => 'Media retrieved.',
            'data' => [...$media->toArray(), 'url' => $media->url()],
        ]);
    }

    public function destroy(Media $media, MediaService $mediaService): JsonResponse
    {
        $this->ensureOwner($media);
        $mediaService->delete($media);

        return response()->json([
            'success' => true,
            'message' => 'Media deleted.',
            'data' => null,
        ]);
    }

    protected function ensureOwner(Media $media): void
    {
        abort_unless($media->user_id === auth()->id(), 403, 'You do not own this file.');
    }
}
