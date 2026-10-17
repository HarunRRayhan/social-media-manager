<?php

namespace App\Publishers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin Graph API caller that turns Meta's error body into a readable exception.
 */
class MetaGraph
{
    public static function url(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function get(string $path, array $params = []): array
    {
        return self::check(Http::timeout(60)->get(self::url($path), $params));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function post(string $path, array $params = []): array
    {
        return self::check(Http::timeout(120)->post(self::url($path), $params));
    }

    /**
     * @return array<string, mixed>
     */
    private static function check(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('Meta: '.($response->json('error.message') ?? "HTTP {$response->status()}"));
        }

        return $response->json() ?? [];
    }

    /** Video by file extension; Meta needs to be told, it does not sniff the URL. */
    public static function isVideo(string $url): bool
    {
        return (bool) preg_match('/\.(mp4|mov)$/i', (string) parse_url($url, PHP_URL_PATH));
    }
}
