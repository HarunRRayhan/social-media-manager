<?php

namespace App\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin LinkedIn API caller. Every failure becomes a RuntimeException whose message holds no URL, token or secret.
 */
class LinkedIn
{
    // The Marketing API version every /rest call pins. Each version lives at least a year; bump it before it sunsets.
    public const VERSION = '202610';

    private const API = 'https://api.linkedin.com/';

    private const OAUTH = 'https://www.linkedin.com/oauth/v2/';

    /**
     * Swap an authorization code for a token (about 60 days; a refresh token only for approved partner apps).
     *
     * @return array<string, mixed>
     */
    public static function login(string $code, string $redirectUri): array
    {
        return self::token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function refresh(string $refreshToken): array
    {
        return self::token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * The signed-in member's OpenID Connect profile: sub, name, picture.
     *
     * @return array<string, mixed>
     */
    public static function userinfo(string $token): array
    {
        return self::send(fn () => Http::timeout(30)->withToken($token)->get(self::API.'v2/userinfo'))->json() ?? [];
    }

    /**
     * POST to a versioned /rest endpoint. Returns the response, since a created post's URN is only in its x-restli-id header.
     *
     * @param  array<string, mixed>  $body
     */
    public static function rest(string $path, string $token, array $body): Response
    {
        return self::send(fn () => Http::timeout(60)->withToken($token)->withHeaders([
            'LinkedIn-Version' => self::VERSION,
            'X-Restli-Protocol-Version' => '2.0.0',
        ])->post(self::API.'rest/'.$path, $body));
    }

    /**
     * PUT image bytes to the upload URL initializeUpload returned.
     */
    public static function upload(string $url, string $token, string $bytes, string $mime): void
    {
        // The token goes along, so it is only ever sent to LinkedIn itself.
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || ($host !== 'linkedin.com' && ! str_ends_with($host, '.linkedin.com'))) {
            throw new RuntimeException('LinkedIn: unexpected upload URL.');
        }

        self::send(fn () => Http::timeout(120)->withToken($token)->withBody($bytes, $mime)->put($url));
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private static function token(array $params): array
    {
        return self::send(fn () => Http::timeout(60)->asForm()->post(self::OAUTH.'accessToken', [
            ...$params,
            'client_id' => config('services.linkedin.client_id'),
            'client_secret' => config('services.linkedin.client_secret'),
        ]))->json() ?? [];
    }

    /**
     * @param  callable(): Response  $request
     */
    private static function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            // Its message carries the full request URL, so it is dropped rather than passed on.
            throw new RuntimeException('LinkedIn: could not reach the API.');
        }

        if ($response->failed()) {
            throw new RuntimeException('LinkedIn: '.($response->json('message') ?? $response->json('error_description') ?? "HTTP {$response->status()}"));
        }

        return $response;
    }
}
