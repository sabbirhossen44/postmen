<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Exchanges an OAuth2 grant for tokens against the token URL stored on
 * the request's auth config. Secrets are sent only to that token URL
 * and are never logged.
 */
class OAuthTokenService
{
    public function __construct(protected Client $client = new Client) {}

    /**
     * @param  array{token_url?: string, client_id?: string, client_secret?: string, redirect_uri?: string, scope?: string, refresh_token?: string}  $config
     * @param  array{grant_type: string, code?: string, redirect_uri?: string, refresh_token?: string, scope?: string}  $grant
     * @return array{access_token: string, token_type?: string, refresh_token?: string, expires_in?: int, scope?: string}
     */
    public function exchange(array $config, array $grant): array
    {
        $tokenUrl = $config['token_url'] ?? '';

        if ($tokenUrl === '') {
            throw new \InvalidArgumentException('No token_url configured on this request.');
        }

        $form = [
            'grant_type' => $grant['grant_type'],
            'client_id' => $config['client_id'] ?? '',
        ];

        if (! empty($config['client_secret'])) {
            $form['client_secret'] = $config['client_secret'];
        }

        match ($grant['grant_type']) {
            'authorization_code' => $form += [
                'code' => $grant['code'] ?? '',
                'redirect_uri' => $grant['redirect_uri'] ?? $config['redirect_uri'] ?? '',
            ],
            'client_credentials' => $form += [
                'scope' => $grant['scope'] ?? $config['scope'] ?? '',
            ],
            'refresh_token' => $form += [
                'refresh_token' => $grant['refresh_token'] ?? $config['refresh_token'] ?? '',
            ],
            default => throw new \InvalidArgumentException('Unsupported grant_type: '.$grant['grant_type']),
        };

        try {
            $response = $this->client->post($tokenUrl, [
                'form_params' => $form,
                'http_errors' => false,
                'timeout' => 30,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Token request failed: '.$e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true) ?? [];

        if ($response->getStatusCode() >= 400 || empty($data['access_token'])) {
            throw new \RuntimeException(
                'Token endpoint returned '.$response->getStatusCode().': '.($data['error_description'] ?? $data['error'] ?? 'no access_token')
            );
        }

        return $data;
    }
}
