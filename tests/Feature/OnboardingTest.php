<?php

namespace Tests\Feature;

use App\Models\ApiRequest;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_seeds_onboarding(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Sabbir',
            'email' => 'sabbir@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $register->assertCreated()->assertJsonPath('success', true);

        $userId = $register->json('data.user.id');

        $workspace = Workspace::where('user_id', $userId)->first();
        $this->assertNotNull($workspace);
        $this->assertSame('My Workspace', $workspace->name);
        $this->assertSame('personal', $workspace->type);

        $collection = Collection::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($collection);
        $this->assertSame('Getting Started', $collection->name);

        $requests = ApiRequest::where('collection_id', $collection->id)
            ->orderBy('sort_order')->get();
        $this->assertCount(4, $requests);
        $this->assertSame(
            [
                ['Get a post', 'GET', 'https://jsonplaceholder.typicode.com/posts/1'],
                ['List users', 'GET', 'https://jsonplaceholder.typicode.com/users'],
                ['Create a post', 'POST', 'https://jsonplaceholder.typicode.com/posts'],
                ['Echo request', 'GET', 'https://httpbin.org/get'],
            ],
            $requests->map(fn (ApiRequest $request) => [$request->name, $request->method, $request->url])->all(),
        );

        foreach ($requests as $request) {
            $this->assertNull($request->folder_id);
            $this->assertSame('none', $request->auth_type);
        }

        $create = $requests->firstWhere('name', 'Create a post');
        $this->assertSame('raw', $create->body_type);
        $this->assertSame(
            '{"title":"Hello from Postmen","body":"My first request.","userId":1}',
            $create->body['raw'],
        );
        $this->assertSame(
            [['key' => 'Content-Type', 'value' => 'application/json', 'enabled' => true]],
            $create->headers,
        );

        $environment = Environment::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($environment);
        $this->assertSame('Sample Environment', $environment->name);
        $this->assertTrue($environment->is_active);
        $this->assertSame(
            'https://jsonplaceholder.typicode.com',
            collect($environment->variables)->firstWhere('key', 'base_url')['value'],
        );
    }

    public function test_relogin_does_not_duplicate_onboarding(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Sabbir',
            'email' => 'sabbir@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'sabbir@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('success', true);

        $userId = User::where('email', 'sabbir@example.com')->first()->id;

        $this->assertSame(1, Workspace::where('user_id', $userId)->count());
        $this->assertSame(1, Collection::where('user_id', $userId)->count());
        $this->assertSame(4, ApiRequest::where('user_id', $userId)->count());
        $this->assertSame(1, Environment::where('user_id', $userId)->count());
    }

    public function test_factory_user_gets_no_onboarding(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0, Workspace::where('user_id', $user->id)->count());
        $this->assertSame(0, Collection::where('user_id', $user->id)->count());
        $this->assertSame(0, ApiRequest::where('user_id', $user->id)->count());
        $this->assertSame(0, Environment::where('user_id', $user->id)->count());
    }
}
