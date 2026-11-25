<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\History;
use App\Repositories\HistoryRepository;
use Illuminate\Http\JsonResponse;

class HistoryController extends Controller
{
    public function index(): JsonResponse
    {
        $histories = HistoryRepository::forUser(auth()->id())->paginate(50);

        return response()->json([
            'success' => true,
            'message' => 'History retrieved.',
            'data' => $histories,
        ]);
    }

    public function show(History $history): JsonResponse
    {
        $this->ensureOwner($history);

        return response()->json([
            'success' => true,
            'message' => 'History entry retrieved.',
            'data' => $history,
        ]);
    }

    /** Delete one entry. */
    public function destroy(History $history): JsonResponse
    {
        $this->ensureOwner($history);
        HistoryRepository::delete($history->id);

        return response()->json([
            'success' => true,
            'message' => 'History entry deleted.',
            'data' => null,
        ]);
    }

    /** Clear all of the user's history. */
    public function destroyAll(): JsonResponse
    {
        HistoryRepository::query()->where('user_id', auth()->id())->delete();

        return response()->json([
            'success' => true,
            'message' => 'History cleared.',
            'data' => null,
        ]);
    }

    protected function ensureOwner(History $history): void
    {
        abort_unless($history->user_id === auth()->id(), 403, 'You do not own this history entry.');
    }
}
