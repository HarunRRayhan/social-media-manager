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
     * @return array<string, mixed>
     */
    public static function delete(string $path, string $token): array
    {
        return self::send(fn () => self::client($token)->delete(self::url($path)));
    }

    /**
     * Have Meta fetch a hosted video into an upload session (Reels publishing API). The token goes in an
     * OAuth Authorization header and the file URL in a `file_url` header, as rupload expects.
     *
     * @return array<string, mixed>
     */
    public static function uploadFromUrl(string $videoId, string $token, string $fileUrl): array
    {
        return self::send(fn () => Http::timeout(120)
            ->withHeaders(['Authorization' => "OAuth {$token}", 'file_url' => $fileUrl])
            ->post('https://rupload.facebook.com/video-upload/'.config('services.meta.graph_version').'/'.$videoId));
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
            $message = 'Meta: '.($response->json('error.message') ?? "HTTP {$response->status()}");

            throw self::needsReconnect($response->json('error.code')) ? new ReconnectRequired($message) : new RuntimeException($message);
        }

        return $response->json() ?? [];
    }

    /** Graph error codes for an invalid token (190) or a missing permission (10, 200 to 299); Threads uses the same ones. */
    public static function needsReconnect(mixed $code): bool
    {
        return is_int($code) && ($code === 190 || $code === 10 || ($code >= 200 && $code < 300));
    }
}
