<?php

namespace App\Services;

use App\Repositories\CookieJarRepository;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\ResponseInterface;

/**
 * Persists a per-user, per-workspace cookie jar in the `cookies` table.
 * Only used when the request's settings.send_cookies is true.
 */
class CookieService
{
    /** Guzzle jar pre-filled with the stored, unexpired cookies. */
    public static function jarFor(int $userId, int $workspaceId): CookieJar
    {
        CookieJarRepository::deleteExpired($userId, $workspaceId);

        $jar = new CookieJar;
        foreach (CookieJarRepository::forUserWorkspace($userId, $workspaceId) as $cookie) {
            $jar->setCookie(new SetCookie([
                'Domain' => $cookie->domain,
                'Name' => $cookie->name,
                'Value' => $cookie->value,
                'Path' => $cookie->path ?? '/',
                'Expires' => $cookie->expires_at?->timestamp,
            ]));
        }

        return $jar;
    }

    /** Store every Set-Cookie from a response; expired ones are removed. */
    public static function storeFromResponse(int $userId, int $workspaceId, ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = SetCookie::fromString($header);

            if ($cookie->getName() === null || $cookie->getDomain() === null) {
                continue;
            }

            if ($cookie->getExpires() !== null && $cookie->getExpires() < time()) {
                CookieJarRepository::query()
                    ->where('user_id', $userId)
                    ->where('workspace_id', $workspaceId)
                    ->where('domain', $cookie->getDomain())
                    ->where('name', $cookie->getName())
                    ->delete();
                continue;
            }

            CookieJarRepository::upsertCookie([
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'domain' => $cookie->getDomain(),
                'name' => $cookie->getName(),
                'value' => $cookie->getValue() ?? '',
                'path' => $cookie->getPath() ?? '/',
                'expires_at' => $cookie->getExpires() !== null
                    ? now()->setTimestamp($cookie->getExpires())
                    : null,
            ]);
        }
    }

    public static function clear(int $userId, int $workspaceId): int
    {
        return CookieJarRepository::clearForWorkspace($userId, $workspaceId);
    }
}
