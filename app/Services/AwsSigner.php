<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Minimal AWS Signature Version 4 signing, dependency-free.
 *
 * Signs a request the way AWS documents it: canonical request, string
 * to sign, derived signing key (HMAC-SHA256 chain), Authorization
 * header. Used by RequestSender for auth_type 'aws'.
 */
class AwsSigner
{
    /**
     * Sign a request and return the headers to send (original headers
     * plus x-amz-date, x-amz-content-sha256 and Authorization).
     *
     * @param  array<string, string>  $headers
     */
    public static function sign(
        string $method,
        string $url,
        array $headers,
        string $body,
        string $accessKey,
        string $secretKey,
        string $region,
        string $service,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (! empty($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        $canonicalUri = self::canonicalUri($parts['path'] ?? '/');
        $canonicalQuery = self::canonicalQuery($parts['query'] ?? '');

        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host'] = $host;
        $headers['x-amz-date'] = $amzDate;

        // The payload hash goes into the canonical request only — it is
        // not a signed header, matching the AWS documentation example
        // (signed headers: host;x-amz-date).
        $payloadHash = $body === 'UNSIGNED-PAYLOAD'
            ? 'UNSIGNED-PAYLOAD'
            : hash('sha256', $body);

        ksort($headers);
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim((string) $value)."\n";
        }

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $dateStamp.'/'.$region.'/'.$service.'/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = self::signingKey($secretKey, $dateStamp, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential='.$accessKey.'/'.$scope
            .', SignedHeaders='.$signedHeaders.', Signature='.$signature;

        // Return with the conventional header casing Guzzle expects.
        $out = [];
        foreach ($headers as $name => $value) {
            $out[self::headerCase($name)] = $value;
        }

        return $out;
    }

    protected static function signingKey(string $secretKey, string $dateStamp, string $region, string $service): string
    {
        $key = hash_hmac('sha256', $dateStamp, 'AWS4'.$secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    protected static function canonicalUri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return implode('/', array_map(
            fn ($segment) => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
    }

    protected static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[rawurlencode(rawurldecode($name))] = rawurlencode(rawurldecode($value));
        }
        ksort($pairs);

        $out = [];
        foreach ($pairs as $name => $value) {
            $out[] = $name.'='.$value;
        }

        return implode('&', $out);
    }

    protected static function headerCase(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', $name)));
    }
}
