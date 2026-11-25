<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = UserRepository::create($request->validated());
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registered successfully.',
            'data' => ['user' => $user->load('media'), 'token' => $token],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = UserRepository::findByEmail($request->email);

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
                'data' => null,
            ], 401);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Logged in successfully.',
            'data' => ['user' => $user->load('media'), 'token' => $token],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
            'data' => null,
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'User retrieved.',
            'data' => $request->user()->load('media'),
        ]);
    }
}
