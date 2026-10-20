<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Publishers\LinkedIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * LinkedIn sign-in for the team: one login connects the personal profile that signed in.
 */
class LinkedInConnectionController extends Controller
{
    use ConnectsWithOAuth;

    // OpenID Connect for the profile, plus w_member_social from the "Share on LinkedIn" product to post.
    private const SCOPES = ['openid', 'profile', 'email', 'w_member_social'];

    /**
     * Send the user to the LinkedIn authorization window.
     */
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(filled(config('services.linkedin.client_id')), 404);
        $state = $this->startOAuth($request, 'linkedin_oauth');

        return redirect()->away('https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.linkedin.client_id'),
            'redirect_uri' => route('connect.linkedin.callback'),
            'state' => $state,
            'scope' => implode(' ', self::SCOPES),
        ]));
    }

    /**
     * Exchange the code for a token and store the member as a LinkedIn account.
     */
    public function callback(Request $request): RedirectResponse
    {
        $team = $this->finishOAuth($request, 'linkedin_oauth');

        if (! $team) {
            return $this->back('error', __('That LinkedIn sign-in expired or did not start here. Please try again.'));
        }

        if (! is_string($request->query('code'))) {
            return $this->back('error', __('LinkedIn connection was cancelled.'));
        }

        try {
            $token = LinkedIn::login($request->query('code'), route('connect.linkedin.callback'));
            $me = LinkedIn::userinfo($token['access_token']);
        } catch (RuntimeException $e) {
            report($e);

            return $this->back('error', __('Could not connect to LinkedIn: :message', ['message' => $e->getMessage()]));
        }

        if (! is_string($me['sub'] ?? null) || $me['sub'] === '') {
            return $this->back('error', __('Could not connect to LinkedIn: it did not say who signed in.'));
        }

        $team->socialAccounts()->updateOrCreate(
            ['provider' => 'linkedin', 'external_id' => 'urn:li:person:'.$me['sub']],
            [
                'name' => ($me['name'] ?? '') ?: $me['sub'],
                'username' => null,
                'avatar_url' => $me['picture'] ?? null,
                'access_token' => $token['access_token'],
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 60 * 24 * 3600)),
                // Only approved partner apps get a refresh token; without one the member signs in again every 60 days.
                'meta' => isset($token['refresh_token']) ? ['refresh_token' => Crypt::encryptString($token['refresh_token'])] : null,
            ],
        );

        return $this->back('success', __('Connected :name on LinkedIn.', ['name' => ($me['name'] ?? '') ?: $me['sub']]));
    }
}
