<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserSettingRequest;
use App\Repositories\UserSettingRepository;
use Illuminate\Http\JsonResponse;

class UserSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = UserSettingRepository::forUserOrCreate(auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'Settings retrieved.',
            'data' => $settings,
        ]);
    }

    public function update(UpdateUserSettingRequest $request): JsonResponse
    {
        $settings = UserSettingRepository::forUserOrCreate(auth()->id());
        UserSettingRepository::update($settings, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Settings updated.',
            'data' => $settings->fresh(),
        ]);
    }
}
