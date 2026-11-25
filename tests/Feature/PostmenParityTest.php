<?php

namespace Tests\Feature;

use App\Models\History;
use App\Models\Media;
use App\Models\User;
use App\Services\RequestSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Phase 0 regression tests for the Postman parity audit (§2.1–2.3, 2.6, 2.8).
class PostmenParityTest extends TestCase
{
    use RefreshDatabase;

    protected function sender(): RequestSender
    {
        return new RequestSender;
    }

    protected function callProtected(object $obj, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }

    public function test_api_key_in_query_uses_query_not_header(): void
    {
        $headers = [];
        $query = [];
        $this->callProtected($this->sender(), 'applyApiKey', [
            ['key' => 'X-Key', 'value' => 'secret', 'in' => 'query'], &$headers, &$query,
        ]);

        $this->assertSame(['X-Key' => 'secret'], $query);
        $this->assertSame([], $headers);
    }

    public function test_api_key_in_header_and_legacy_add_to_alias(): void
    {
        $headers = [];
        $query = [];
        $this->callProtected($this->sender(), 'applyApiKey', [
            ['key' => 'X-Key', 'value' => 'secret', 'in' => 'header'], &$headers, &$query,
        ]);
        $this->assertSame(['X-Key' => 'secret'], $headers);

        // Legacy frontend name `add_to` still works as an alias.
        $headers = [];
        $query = [];
        $this->callProtected($this->sender(), 'applyApiKey', [
            ['key' => 'X-Key', 'value' => 'secret', 'add_to' => 'query'], &$headers, &$query,
        ]);
        $this->assertSame(['X-Key' => 'secret'], $query);
    }

    public function test_form_data_and_urlencoded_rows_are_sent(): void
    {
        $user = User::factory()->create();
        $options = $this->callProtected($this->sender(), 'buildOptions', [
            ['body_type' => 'form-data', 'body' => ['formdata' => [['key' => 'name', 'value' => 'Sabbir', 'type' => 'text', 'enabled' => true]]]],
            [], $user,
        ]);
        $this->assertSame([['name' => 'name', 'contents' => 'Sabbir']], $options['multipart']);

        $options = $this->callProtected($this->sender(), 'buildOptions', [
            ['body_type' => 'urlencoded', 'body' => ['urlencoded' => [['key' => 'a', 'value' => '1', 'enabled' => true]]]],
            [], $user,
        ]);
        $this->assertSame(['a' => '1'], $options['form_params']);
    }

    public function test_form_data_file_row_sends_real_bytes_not_path(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Storage::disk('public')->put('media/test.txt', 'hello-file-bytes');
        $media = Media::create([
            'user_id' => $user->id, 'disk' => 'public', 'path' => 'media/test.txt',
            'original_name' => 'test.txt', 'mime_type' => 'text/plain', 'size' => 16,
        ]);

        $options = $this->callProtected($this->sender(), 'buildOptions', [
            ['body_type' => 'form-data', 'body' => ['formdata' => [['key' => 'file', 'type' => 'file', 'media_id' => $media->id, 'enabled' => true]]]],
            [], $user,
        ]);

        $part = $options['multipart'][0];
        $this->assertSame('file', $part['name']);
        $this->assertSame('test.txt', $part['filename']);
        $this->assertIsResource($part['contents']);
        $this->assertSame('hello-file-bytes', stream_get_contents($part['contents']));
    }

    public function test_binary_body_is_sent_from_media_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Storage::disk('public')->put('media/blob.bin', 'binary-bytes');
        $media = Media::create([
            'user_id' => $user->id, 'disk' => 'public', 'path' => 'media/blob.bin',
            'original_name' => 'blob.bin', 'mime_type' => 'application/octet-stream', 'size' => 12,
        ]);

        $options = $this->callProtected($this->sender(), 'buildOptions', [
            ['body_type' => 'binary', 'body_file_media_id' => $media->id],
            [], $user,
        ]);

        $this->assertIsResource($options['body']);
        $this->assertSame('binary-bytes', stream_get_contents($options['body']));
        $this->assertSame('application/octet-stream', $options['headers']['Content-Type']);
    }

    public function test_binary_body_is_sent_from_inline_base64(): void
    {
        $user = User::factory()->create();
        $options = $this->callProtected($this->sender(), 'buildOptions', [
            ['body_type' => 'binary', 'body' => ['binary' => base64_encode('inline-bytes')]],
            [], $user,
        ]);
        $this->assertSame('inline-bytes', $options['body']);
    }

    public function test_history_records_request_snapshot_and_paginates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // A blocked send still records history — with a full snapshot.
        $this->postJson('/api/send', [
            'method' => 'GET',
            'url' => 'http://localhost:3000/users',
            'params' => [['key' => 'page', 'value' => '2', 'enabled' => true]],
            'headers' => [['key' => 'X-Test', 'value' => 'yes', 'enabled' => true]],
            'auth_type' => 'none',
            'body_type' => 'none',
        ])->assertStatus(422);

        $history = History::first();
        $this->assertNotNull($history);
        $this->assertSame('GET', $history->request_snapshot['method']);
        $this->assertSame('http://localhost:3000/users', $history->request_snapshot['url']);
        $this->assertSame('page', $history->request_snapshot['params'][0]['key']);

        // 51 rows: page 1 has 50, page 2 has the rest.
        for ($i = 0; $i < 50; $i++) {
            History::create(['user_id' => $user->id, 'method' => 'GET', 'url' => 'https://example.com/'.$i]);
        }
        $page1 = $this->getJson('/api/histories?page=1')->assertOk()->json('data');
        $this->assertCount(50, $page1['data']);
        $this->assertSame(2, $page1['last_page']);
        $page2 = $this->getJson('/api/histories?page=2')->assertOk()->json('data');
        $this->assertCount(1, $page2['data']);
    }
}
