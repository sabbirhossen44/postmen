<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RequestSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SSRF guard (config/postmen.php, ALLOW_PRIVATE_IPS, default true).
 *
 * Sabbir's blocker: GET http://allsolution.test/api/master failed with
 * 422 "Requests to private or reserved IP addresses are not allowed."
 * because allsolution.test resolves to loopback. Local dev hosts must
 * work by default; a public deployment can still refuse them.
 */
class SsrfGuardTest extends TestCase
{
    use RefreshDatabase;

    protected RequestSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = new RequestSender;
    }

    public function test_localhost_and_private_urls_allowed_by_default(): void
    {
        config(['postmen.allow_private_ips' => true]);

        foreach ([
            'http://localhost:8000/x',
            'http://localhost/x',
            'http://allsolution.test/api/master',
            'http://api.local/x',
            'http://127.0.0.1/x',
            'http://192.168.1.5/x',
            'http://10.0.0.9/x',
            'http://8.8.8.8/x',
        ] as $url) {
            $this->assertNull(
                $this->sender->ssrfError($url),
                "Expected $url to be allowed with allow_private_ips=true",
            );
        }
    }

    public function test_localhost_and_private_urls_rejected_when_toggle_off(): void
    {
        config(['postmen.allow_private_ips' => false]);

        foreach ([
            'http://localhost:8000/admin',
            'http://127.0.0.1/secret',
            'http://192.168.1.5/x',
            'http://10.0.0.9/x',
        ] as $url) {
            $this->assertNotNull(
                $this->sender->ssrfError($url),
                "Expected $url to be rejected with allow_private_ips=false",
            );
        }
    }

    public function test_metadata_ip_always_blocked(): void
    {
        foreach ([true, false] as $allowed) {
            config(['postmen.allow_private_ips' => $allowed]);

            foreach ([
                'http://169.254.169.254/latest/meta-data/',
                'http://169.254.169.254/',
                'http://169.254.169.253/x',
            ] as $url) {
                $error = $this->sender->ssrfError($url);
                $this->assertNotNull($error, "Expected $url to be blocked (allow=$allowed)");
                $this->assertStringContainsString('metadata', strtolower($error));
            }
        }
    }

    public function test_send_to_test_host_is_no_longer_an_ssrf_422(): void
    {
        // Regression test for Sabbir's screenshot: the send must not fail
        // with the SSRF message. It still fails here (nothing listens on
        // port 1), but with a connection error, not the guard.
        config(['postmen.allow_private_ips' => true]);
        $user = User::factory()->create();

        $result = (new RequestSender)->send(
            ['method' => 'GET', 'url' => 'http://allsolution.test:1/api/master'],
            $user,
        );

        $this->assertNotNull($result['error']);
        $this->assertStringNotContainsString('private or reserved', $result['error']);
        $this->assertStringNotContainsString('localhost are not allowed', $result['error']);
    }
}
