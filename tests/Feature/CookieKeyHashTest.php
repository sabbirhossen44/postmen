<?php

namespace Tests\Feature;

use App\Models\StoredCookie;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\CookieJarRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CookieKeyHashTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_dedupes_by_key_hash(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['user_id' => $user->id, 'name' => 'WS']);

        $attributes = [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'domain' => 'example.test',
            'name' => 'session',
            'value' => 'first',
            'path' => '/',
        ];

        $first = CookieJarRepository::upsertCookie($attributes);
        $second = CookieJarRepository::upsertCookie(array_merge($attributes, ['value' => 'second']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('second', $second->fresh()->value);
        $this->assertCount(1, StoredCookie::all());
        $this->assertSame(
            hash('sha256', implode('|', [$user->id, $workspace->id, 'example.test', 'session', '/'])),
            $second->fresh()->key_hash,
        );
    }

    public function test_model_computes_key_hash_on_create(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['user_id' => $user->id, 'name' => 'WS']);

        $cookie = StoredCookie::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'domain' => 'example.test',
            'name' => 'token',
            'value' => 'abc',
            'path' => '/',
        ]);

        $this->assertNotNull($cookie->key_hash);
        $this->assertSame(64, strlen($cookie->key_hash));
    }
}
