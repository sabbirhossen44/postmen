<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Repositories\ApiRequestRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\EnvironmentRepository;
use App\Repositories\WorkspaceRepository;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a brand-new account with something to try right away: a personal
 * workspace, a "Getting Started" collection with four sample requests, and
 * a sample environment. Idempotent — a user who already has a workspace
 * gets nothing new. No activity log is written for the seed.
 */
class OnboardingService
{
    public function seedFor(User $user): ?Workspace
    {
        $existing = WorkspaceRepository::query()
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($user) {
            $workspace = WorkspaceRepository::create([
                'user_id' => $user->id,
                'name' => 'My Workspace',
                'type' => 'personal',
                'description' => 'Your personal workspace.',
            ]);

            $collection = CollectionRepository::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'name' => 'Getting Started',
                'description' => 'Sample requests to try Postmen. Send one, then edit it or make your own.',
            ]);

            foreach ($this->sampleRequests() as $index => $request) {
                ApiRequestRepository::create([
                    'collection_id' => $collection->id,
                    'folder_id' => null,
                    'user_id' => $user->id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'name' => $request['name'],
                    'method' => $request['method'],
                    'url' => $request['url'],
                    'params' => [],
                    'headers' => $request['headers'] ?? [],
                    'auth_type' => 'none',
                    'body_type' => $request['body_type'],
                    'body' => $request['body'] ?? null,
                    'sort_order' => $index + 1,
                ]);
            }

            EnvironmentRepository::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'name' => 'Sample Environment',
                'is_active' => true,
                'variables' => [
                    ['key' => 'base_url', 'value' => 'https://jsonplaceholder.typicode.com', 'enabled' => true],
                    ['key' => 'user_id', 'value' => '1', 'enabled' => true],
                ],
            ]);

            return $workspace;
        });
    }

    /** The four sample requests, in the order they appear. */
    protected function sampleRequests(): array
    {
        return [
            [
                'name' => 'Get a post',
                'method' => 'GET',
                'url' => 'https://jsonplaceholder.typicode.com/posts/1',
                'body_type' => 'none',
            ],
            [
                'name' => 'List users',
                'method' => 'GET',
                'url' => 'https://jsonplaceholder.typicode.com/users',
                'body_type' => 'none',
            ],
            [
                'name' => 'Create a post',
                'method' => 'POST',
                'url' => 'https://jsonplaceholder.typicode.com/posts',
                'body_type' => 'raw',
                'headers' => [
                    ['key' => 'Content-Type', 'value' => 'application/json', 'enabled' => true],
                ],
                // Raw bodies are stored as ['raw' => ..., 'raw_language' => ...]
                // (see RequestSender); a plain string would be re-encoded.
                'body' => [
                    'raw' => '{"title":"Hello from Postmen","body":"My first request.","userId":1}',
                    'raw_language' => 'json',
                ],
            ],
            [
                'name' => 'Echo request',
                'method' => 'GET',
                'url' => 'https://httpbin.org/get',
                'body_type' => 'none',
            ],
        ];
    }
}
