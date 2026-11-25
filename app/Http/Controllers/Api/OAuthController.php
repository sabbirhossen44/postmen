<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeOAuthTokenRequest;
use App\Models\ApiRequest;
use App\Services\OAuthTokenService;
use Illuminate\Http\JsonResponse;

class OAuthController extends Controller
{
    /**
     * Exchange a grant for tokens and store them on the request's auth
     * config (access_token / refresh_token / token_type / expires_at).
     * Secrets are sent only to the configured token URL, never logged.
     */
    public function exchange(ExchangeOAuthTokenRequest $request, ApiRequest $apiRequest, OAuthTokenService $service): JsonResponse
    {
        abort_unless($apiRequest->user_id === auth()->id(), 403, 'You do not own this request.');

        try {
            $token = $service->exchange($apiRequest->auth ?? [], $request->validated());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }

        $auth = $apiRequest->auth ?? [];
        $auth['access_token'] = $token['access_token'];
        $auth['token_type'] = $token['token_type'] ?? 'Bearer';
        if (! empty($token['refresh_token'])) {
            $auth['refresh_token'] = $token['refresh_token'];
        }
        if (! empty($token['expires_in'])) {
            $auth['expires_at'] = now()->addSeconds((int) $token['expires_in'])->toIso8601String();
        }
        $apiRequest->auth = $auth;
        $apiRequest->save();

        return response()->json([
            'success' => true,
            'message' => 'Access token obtained.',
            'data' => [
                'access_token' => $token['access_token'],
                'token_type' => $auth['token_type'],
                'expires_in' => $token['expires_in'] ?? null,
                'scope' => $token['scope'] ?? null,
            ],
        ]);
    }
}
