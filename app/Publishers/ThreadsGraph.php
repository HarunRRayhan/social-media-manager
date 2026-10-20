<?php

namespace App\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin Threads API caller, the graph.threads.com twin of MetaGraph. Every failure becomes a RuntimeException
 * whose message holds no URL, token or secret.
 */
class ThreadsGraph
{
    private const HOST = 'https://graph.threads.com/';

    private const VERSION = 'v1.0';

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function get(string $path, string $token, array $query = []): array
    {
        return self::send(fn () => Http::timeout(30)->withToken($token)->get(self::HOST.self::VERSION.'/'.$path, $query));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function post(string $path, string $token, array $params = []): array
    {
        return self::send(fn () => Http::timeout(120)->withToken($token)->post(self::HOST.self::VERSION.'/'.$path, $params));
    }

    /**
     * Swap an authorization code for a short-lived token, then that for a 60-day one.
     *
     * @return array<string, mixed>
     */
    public static function login(string $code, string $redirectUri): array
    {
        $short = self::send(fn () => Http::timeout(60)->asForm()->post(self::HOST.'oauth/access_token', [
            'client_id' => config('services.threads.app_id'),
            'client_secret' => config('services.threads.app_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]))['access_token'];

        // The API only takes these as a GET query; send() keeps the URL out of any error.
        return self::send(fn () => Http::timeout(60)->get(self::HOST.'access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => config('services.threads.app_secret'),
            'access_token' => $short,
        ]));
    }

    /**
     * Extend a long-lived token (at least a day old, not yet expired) by another 60 days.
     *
     * @return array<string, mixed>
     */
    public static function refresh(string $token): array
    {
        return self::send(fn () => Http::timeout(60)->get(self::HOST.'refresh_access_token', [
            'grant_type' => 'th_refresh_token',
            'access_token' => $token,
        ]));
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
            throw new RuntimeException('Threads: could not reach the API.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Threads: '.($response->json('error.message') ?? $response->json('error_message') ?? "HTTP {$response->status()}"));
        }

        return $response->json() ?? [];
    }
}
