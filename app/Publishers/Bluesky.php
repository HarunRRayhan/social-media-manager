<?php

namespace App\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin AT Protocol caller for bsky.social. The app password and session token travel only in
 * request bodies or headers, and every failure becomes a RuntimeException holding neither.
 */
class Bluesky
{
    // shortcut: talks to the bsky.social entryway, so accounts on a self-hosted PDS are not supported yet.
    public const HOST = 'https://bsky.social/xrpc/';

    /**
     * Sign in with a handle (or DID) and an app password.
     *
     * @return array<string, mixed> holds `did`, `handle` and `accessJwt`
     */
    public static function session(string $identifier, string $password): array
    {
        return self::send(fn () => Http::timeout(30)->post(self::HOST.'com.atproto.server.createSession', [
            'identifier' => $identifier,
            'password' => $password,
        ]));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function post(string $method, string $token, array $body): array
    {
        return self::send(fn () => Http::timeout(60)->withToken($token)->post(self::HOST.$method, $body));
    }

    /**
     * Upload raw bytes and return the blob reference a record embeds.
     *
     * @return array<string, mixed>
     */
    public static function upload(string $token, string $bytes, string $mime): array
    {
        return self::send(fn () => Http::timeout(120)->withToken($token)->withBody($bytes, $mime)
            ->post(self::HOST.'com.atproto.repo.uploadBlob'))['blob'];
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
            throw new RuntimeException('Bluesky: could not reach bsky.social.');
        }

        if ($response->failed()) {
            $message = 'Bluesky: '.($response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}");

            // 401 is a revoked or changed app password.
            throw $response->status() === 401 ? new ReconnectRequired($message) : new RuntimeException($message);
        }

        return $response->json() ?? [];
    }
}
