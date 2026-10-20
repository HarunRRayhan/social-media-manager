<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Publishers\ThreadsGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Threads sign-in for the team: one login connects the one Threads profile that signed in, no picker needed.
 */
class ThreadsConnectionController extends Controller
{
    use ConnectsWithOAuth;

    private const SCOPES = ['threads_basic', 'threads_content_publish'];

    /**
     * Send the user to the Threads authorization window.
     */
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(filled(config('services.threads.app_id')), 404);
        $state = $this->startOAuth($request, 'threads_oauth');

        return redirect()->away('https://threads.com/oauth/authorize?'.http_build_query([
            'client_id' => config('services.threads.app_id'),
            'redirect_uri' => route('connect.threads.callback'),
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]));
    }

    /**
     * Exchange the code for a 60-day token and store the profile as a Threads account.
     */
    public function callback(Request $request): RedirectResponse
    {
        $team = $this->finishOAuth($request, 'threads_oauth');

        if (! $team) {
            return $this->back('error', __('That Threads sign-in expired or did not start here. Please try again.'));
        }

        if (! is_string($request->query('code'))) {
            return $this->back('error', __('Threads connection was cancelled.'));
        }

        try {
            $token = ThreadsGraph::login($request->query('code'), route('connect.threads.callback'));
            $me = ThreadsGraph::get('me', $token['access_token'], ['fields' => 'id,username,name,threads_profile_picture_url']);
        } catch (RuntimeException $e) {
            report($e);

            return $this->back('error', __('Could not connect to Threads: :message', ['message' => $e->getMessage()]));
        }

        $team->socialAccounts()->updateOrCreate(
            ['provider' => 'threads', 'external_id' => (string) $me['id']],
            [
                'name' => ($me['name'] ?? '') ?: ($me['username'] ?? (string) $me['id']),
                'username' => $me['username'] ?? null,
                'avatar_url' => $me['threads_profile_picture_url'] ?? null,
                'access_token' => $token['access_token'],
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 60 * 24 * 3600)),
            ],
        );

        return $this->back('success', __('Connected @:username on Threads.', ['username' => $me['username'] ?? $me['id']]));
    }
}
