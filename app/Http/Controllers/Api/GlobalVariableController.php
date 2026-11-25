<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGlobalVariableRequest;
use App\Http\Requests\UpdateGlobalVariableRequest;
use App\Models\GlobalVariable;
use App\Repositories\GlobalVariableRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalVariableController extends Controller
{
    public function index(): JsonResponse
    {
        $globals = GlobalVariableRepository::forUser(auth()->id())
            ->map(fn (GlobalVariable $global) => $global->toDisplayArray());

        return response()->json([
            'success' => true,
            'message' => 'Global variables retrieved.',
            'data' => $globals,
        ]);
    }

    public function store(StoreGlobalVariableRequest $request): JsonResponse
    {
        $global = GlobalVariableRepository::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Global variable created.',
            'data' => $global->toDisplayArray(),
        ], 201);
    }

    /**
     * Show one global. Secret values stay hidden unless the owner
     * explicitly asks (?include_secrets=1) for the edit fetch.
     */
    public function show(Request $request, GlobalVariable $globalVariable): JsonResponse
    {
        $this->ensureOwner($globalVariable);

        return response()->json([
            'success' => true,
            'message' => 'Global variable retrieved.',
            'data' => $globalVariable->toDisplayArray($request->boolean('include_secrets')),
        ]);
    }

    public function update(UpdateGlobalVariableRequest $request, GlobalVariable $globalVariable): JsonResponse
    {
        $this->ensureOwner($globalVariable);
        GlobalVariableRepository::update($globalVariable, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Global variable updated.',
            'data' => $globalVariable->fresh()->toDisplayArray(),
        ]);
    }

    public function destroy(GlobalVariable $globalVariable): JsonResponse
    {
        $this->ensureOwner($globalVariable);
        GlobalVariableRepository::delete($globalVariable->id);

        return response()->json([
            'success' => true,
            'message' => 'Global variable deleted.',
            'data' => null,
        ]);
    }

    protected function ensureOwner(GlobalVariable $globalVariable): void
    {
        abort_unless($globalVariable->user_id === auth()->id(), 403, 'You do not own this global variable.');
    }
}
