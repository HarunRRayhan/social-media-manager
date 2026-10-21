<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Publishers\X;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * X sign-in for the team: OAuth 2.0 authorization code with PKCE connects the account that signed in.
 */
class XConnectionController extends Controller
{
    use ConnectsWithOAuth;

    // Read the profile, write posts and media; offline.access adds the refresh token the 2-hour token needs.
    public const SCOPES = ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'];

    /**
     * Send the user to the X authorization window.
     */
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(filled(config('services.x.client_id')), 404);
        $state = $this->startOAuth($request, 'x_oauth');
        $verifier = Str::random(64);
        $request->session()->put('x_oauth_verifier', $verifier);

        return redirect()->away(X::AUTHORIZE.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.x.client_id'),
            'redirect_uri' => route('connect.x.callback'),
            'state' => $state,
            'scope' => implode(' ', self::SCOPES),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }

    /**
     * Exchange the code for tokens and store the user as an X account.
     */
    public function callback(Request $request): RedirectResponse
    {
        $team = $this->finishOAuth($request, 'x_oauth');
        $verifier = $request->session()->pull('x_oauth_verifier');

        if (! $team || ! is_string($verifier)) {
            return $this->back('error', __('That X sign-in expired or did not start here. Please try again.'));
        }

        if (! is_string($request->query('code'))) {
            return $this->back('error', __('X connection was cancelled.'));
        }

        try {
            $token = X::login($request->query('code'), route('connect.x.callback'), $verifier);
            $attributes = X::tokenAttributes($token);
            $me = X::me($token['access_token']);
        } catch (RuntimeException $e) {
            report($e);

            return $this->back('error', __('Could not connect to X: :message', ['message' => $e->getMessage()]));
        }

        if (! is_string($me['id'] ?? null) || $me['id'] === '') {
            return $this->back('error', __('Could not connect to X: it did not say who signed in.'));
        }

        $name = ($me['name'] ?? '') ?: ($me['username'] ?? $me['id']);

        $team->socialAccounts()->updateOrCreate(
            ['provider' => 'x', 'external_id' => $me['id']],
            [
                'name' => $name,
                'username' => $me['username'] ?? null,
                'avatar_url' => $me['profile_image_url'] ?? null,
                ...$attributes,
            ],
        );

        return $this->back('success', __('Connected :name on X.', ['name' => $name]));
    }
}
