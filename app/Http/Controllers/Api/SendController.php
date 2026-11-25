<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendRequestRequest;
use App\Models\ApiRequest;
use App\Repositories\CollectionRepository;
use App\Repositories\EnvironmentRepository;
use App\Services\RequestSender;
use App\Services\VariableResolver;
use Illuminate\Http\JsonResponse;

class SendController extends Controller
{
    public function send(SendRequestRequest $request, RequestSender $sender): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $savedRequest = null;
        if (! empty($data['request_id'])) {
            $savedRequest = ApiRequest::where('id', $data['request_id'])
                ->where('user_id', $user->id)->first();
            abort_unless($savedRequest, 404, 'Request not found.');
        }

        // Environment variables: the chosen environment, else the
        // workspace's active environment (via collection or environment).
        $environmentVariables = [];
        $collectionVariables = [];

        if (! empty($data['environment_id'])) {
            $environment = EnvironmentRepository::findOrFail($data['environment_id']);
            abort_unless($environment->user_id === $user->id, 403, 'You do not own this environment.');
            $environmentVariables = $environment->variables ?? [];
        } elseif (! empty($data['collection_id'])) {
            $collection = CollectionRepository::findForUser((int) $data['collection_id'], $user->id);
            $environment = $collection
                ? EnvironmentRepository::activeForWorkspace($collection->workspace_id)
                : null;
            $environmentVariables = $environment?->variables ?? [];
        }

        if (! empty($data['collection_id'])) {
            $collection = CollectionRepository::findForUser((int) $data['collection_id'], $user->id);
            $collectionVariables = $collection?->variables ?? [];
        }

        $variables = VariableResolver::merge($environmentVariables, $collectionVariables);

        $result = $sender->send([
            ...$data,
            'variables' => $variables,
        ], ($data['save_history'] ?? true) ? $user : null, $savedRequest);

        $status = $result['error'] !== null && $result['status'] === null ? 422 : 200;

        return response()->json([
            'success' => $result['error'] === null,
            'message' => $result['error'] ?? 'Request sent.',
            'data' => $result,
        ], $status);
    }
}
