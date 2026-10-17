<?php

namespace App\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin Graph API caller. Tokens and secrets travel only in headers or request bodies, never in a URL,
 * and every failure becomes a RuntimeException whose message holds no URL, token or secret.
 */
class MetaGraph
{
    public static function url(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function get(string $path, string $token, array $query = []): array
    {
        return self::send(fn () => self::client($token)->get(self::url($path), $query));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function post(string $path, string $token, array $params = []): array
    {
        return self::send(fn () => self::client($token)->post(self::url($path), $params));
    }

    /**
     * The OAuth token endpoint, with the app secret in a form body.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function exchange(array $params): array
    {
        return self::send(fn () => Http::timeout(60)->asForm()->post(self::url('oauth/access_token'), [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            ...$params,
        ]));
    }

    /** Video by file extension; Meta needs to be told, it does not sniff the URL. */
    public static function isVideo(string $url): bool
    {
        return (bool) preg_match('/\.(mp4|mov)$/i', (string) parse_url($url, PHP_URL_PATH));
    }

    private static function client(string $token): PendingRequest
    {
        return Http::timeout(120)->withToken($token);
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     */
    private static function send(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            // Its message carries the full request URL, so it is dropped rather than passed on.
            throw new RuntimeException('Meta: could not reach the Graph API.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Meta: '.($response->json('error.message') ?? "HTTP {$response->status()}"));
        }

        return $response->json() ?? [];
    }
}
