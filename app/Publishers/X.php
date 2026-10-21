<?php

namespace App\Publishers;

use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin X API v2 caller. Every failure becomes a RuntimeException whose message holds no URL, token or secret.
 */
class X
{
    public const API = 'https://api.x.com/2/';

    public const AUTHORIZE = 'https://x.com/i/oauth2/authorize';

    /**
     * Swap a PKCE authorization code for an access token (2 hours) and, with offline.access, a refresh token.
     *
     * @return array<string, mixed>
     */
    public static function login(string $code, string $redirectUri, string $verifier): array
    {
        return self::token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $verifier]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function refresh(string $refreshToken): array
    {
        return self::token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * Return the account with a token good for at least $minutes more, renewing it first when needed.
     * Refresh tokens are single-use, so the row is locked while one is spent; a failed renewal flags the account
     * for reconnecting and the flag stays until the user signs in again.
     *
     * @throws ReconnectRequired when the account has no usable refresh token
     */
    public static function fresh(SocialAccount $account, int $minutes = 5): SocialAccount
    {
        if ($account->token_expires_at?->gt(now()->addMinutes($minutes))) {
            return $account;
        }

        try {
            return DB::transaction(function () use ($account, $minutes) {
                $locked = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

                // Another worker renewed it while this one waited for the lock.
                if ($locked->token_expires_at?->gt(now()->addMinutes($minutes))) {
                    return $locked;
                }

                if (! is_string($locked->meta['refresh_token'] ?? null)) {
                    throw new ReconnectRequired('X: the account must be connected again.');
                }

                $current = Crypt::decryptString($locked->meta['refresh_token']);
                $locked->update(self::tokenAttributes(self::refresh($current), $current));

                return $locked;
            });
        } catch (ReconnectRequired $e) {
            // Outside the transaction, which the exception rolled back. The flag is cleared by a new sign-in.
            $account->update(['meta' => ['reconnect' => true]]);

            throw $e;
        }
    }

    /**
     * The access_token, token_expires_at and meta columns a token response fills.
     *
     * @param  array<string, mixed>  $token
     * @param  string|null  $currentRefreshToken  kept when a refresh response rotates no new one
     * @return array<string, mixed>
     */
    public static function tokenAttributes(array $token, ?string $currentRefreshToken = null): array
    {
        $refreshToken = $token['refresh_token'] ?? $currentRefreshToken;

        if (! is_string($token['access_token'] ?? null) || ! is_string($refreshToken)) {
            throw new RuntimeException('X: did not return an access and refresh token.');
        }

        return [
            'access_token' => $token['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 7200)),
            'meta' => ['refresh_token' => Crypt::encryptString($refreshToken)],
        ];
    }

    /**
     * The signed-in user: id, username, name, profile_image_url.
     *
     * @return array<string, mixed>
     */
    public static function me(string $token): array
    {
        return self::send(fn () => self::client($token)->get(self::API.'users/me', ['user.fields' => 'id,name,username,profile_image_url']))->json('data') ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function post(string $path, string $token, array $body): array
    {
        return self::send(fn () => self::client($token)->post(self::API.$path, $body))->json('data') ?? [];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    public static function get(string $path, string $token, array $query = []): array
    {
        return self::send(fn () => self::client($token)->get(self::API.$path, $query))->json('data') ?? [];
    }

    public static function delete(string $path, string $token): void
    {
        self::send(fn () => self::client($token)->delete(self::API.$path));
    }

    /**
     * One APPEND segment of a chunked media upload.
     */
    public static function append(string $mediaId, string $token, int $index, string $bytes): void
    {
        self::send(fn () => self::client($token)->timeout(120)->asMultipart()
            ->attach('media', $bytes, 'segment')
            ->post(self::API."media/upload/{$mediaId}/append", ['segment_index' => (string) $index]));
    }

    private static function client(string $token): PendingRequest
    {
        return Http::timeout(60)->withToken($token);
    }

    /**
     * The app is a confidential client, so it authenticates with Basic auth instead of client_id in the body.
     *
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private static function token(array $params): array
    {
        return self::send(fn () => Http::timeout(60)->asForm()
            ->withBasicAuth((string) config('services.x.client_id'), (string) config('services.x.client_secret'))
            ->post(self::API.'oauth2/token', $params), tokenRequest: true)->json() ?? [];
    }

    /**
     * @param  callable(): Response  $request
     * @param  bool  $tokenRequest  a refused code or refresh token means the user has to sign in again
     */
    private static function send(callable $request, bool $tokenRequest = false): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            // Its message carries the full request URL, so it is dropped rather than passed on.
            throw new RuntimeException('X: could not reach the API.');
        }

        if ($response->failed()) {
            $message = collect([$response->json('detail'), $response->json('errors.0.message'), $response->json('error_description'), $response->json('title'), $response->json('error')])
                ->first(fn ($value) => is_string($value) && $value !== '');

            $message = 'X: '.($message ?? "HTTP {$response->status()}");

            if ($response->status() === 429) {
                $reset = (int) $response->header('x-rate-limit-reset');

                throw new RuntimeException($message.' (rate limit'.($reset ? ', resets at '.date('H:i', $reset).' UTC' : '').'). It was not retried.');
            }

            // 401 on the API is an expired or revoked token; on the token endpoint a refused code or refresh token
            // is a 400, while 401 there is the app's own client secret and must not flag the account.
            throw ($tokenRequest ? $response->status() === 400 : $response->status() === 401) ? new ReconnectRequired($message) : new RuntimeException($message);
        }

        return $response;
    }
}
