<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequestRequest;
use App\Http\Requests\UpdateRequestRequest;
use App\Models\ApiRequest;
use App\Repositories\ApiRequestRepository;
use App\Repositories\CollectionRepository;
use Illuminate\Http\JsonResponse;

class RequestController extends Controller
{
    public function store(StoreRequestRequest $request): JsonResponse
    {
        $this->ownedCollection((int) $request->collection_id);

        $apiRequest = ApiRequestRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Request created.',
            'data' => $apiRequest,
        ], 201);
    }

    public function show(ApiRequest $apiRequest): JsonResponse
    {
        $this->ensureOwner($apiRequest);

        return response()->json([
            'success' => true,
            'message' => 'Request retrieved.',
            'data' => $apiRequest->load(['collection', 'folder', 'bodyFile']),
        ]);
    }

    public function update(UpdateRequestRequest $request, ApiRequest $apiRequest): JsonResponse
    {
        $this->ensureOwner($apiRequest);
        ApiRequestRepository::update($apiRequest, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Request updated.',
            'data' => $apiRequest->fresh(),
        ]);
    }

    public function destroy(ApiRequest $apiRequest): JsonResponse
    {
        $this->ensureOwner($apiRequest);
        ApiRequestRepository::delete($apiRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Request deleted.',
            'data' => null,
        ]);
    }

    public function duplicate(ApiRequest $apiRequest): JsonResponse
    {
        $this->ensureOwner($apiRequest);

        $copy = $apiRequest->replicate()->fill([
            'name' => $apiRequest->name.' (copy)',
        ]);
        $copy->save();

        return response()->json([
            'success' => true,
            'message' => 'Request duplicated.',
            'data' => $copy,
        ], 201);
    }

    protected function ownedCollection(int $collectionId): void
    {
        abort_unless(CollectionRepository::findForUser($collectionId, auth()->id()), 404, 'Collection not found.');
    }

    protected function ensureOwner(ApiRequest $apiRequest): void
    {
        abort_unless($apiRequest->user_id === auth()->id(), 403, 'You do not own this request.');
    }
}
