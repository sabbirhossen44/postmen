<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateCodeRequest;
use App\Models\ApiRequest;
use App\Services\CodeGenerator;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CodeController extends Controller
{
    public function show(Request $request, ApiRequest $apiRequest, CodeGenerator $generator): JsonResponse
    {
        $this->ensureOwner($apiRequest);

        $language = $request->query('language', 'curl');

        try {
            $code = $generator->generate($this->normalised($apiRequest), $language);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code generated.',
            'data' => ['language' => $language, 'code' => $code],
        ]);
    }

    public function generate(GenerateCodeRequest $request, CodeGenerator $generator): JsonResponse
    {
        $code = $generator->generate($request->validated(), $request->language);

        return response()->json([
            'success' => true,
            'message' => 'Code generated.',
            'data' => ['language' => $request->language, 'code' => $code],
        ]);
    }

    protected function normalised(ApiRequest $apiRequest): array
    {
        return [
            'method' => $apiRequest->method,
            'url' => $apiRequest->url,
            'params' => $apiRequest->params ?? [],
            'headers' => $apiRequest->headers ?? [],
            'auth_type' => $apiRequest->auth_type,
            'auth' => $apiRequest->auth,
            'body_type' => $apiRequest->body_type,
            'body' => $apiRequest->body,
        ];
    }

    protected function ensureOwner(ApiRequest $apiRequest): void
    {
        WorkspaceAccess::requireCollectionView(auth()->user(), $apiRequest->collection);
    }
}
