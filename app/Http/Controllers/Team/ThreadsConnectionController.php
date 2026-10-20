<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Publishers\ThreadsGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;

/**
 * Threads sign-in for the team: one login connects the one Threads profile that signed in, no picker needed.
 */
class ThreadsConnectionController extends Controller
{
    private const SCOPES = ['threads_basic', 'threads_content_publish'];

    /**
     * Send the user to the Threads authorization window.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);
        abort_unless(filled(config('services.threads.app_id')), 404);

        $state = Str::random(40);
        $request->session()->put('threads_oauth', ['state' => $state, 'team_id' => $team->id]);

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
        $flow = $request->session()->pull('threads_oauth');

        if (! $flow || ! is_string($request->query('state')) || ! hash_equals($flow['state'], $request->query('state'))) {
            return $this->back('error', __('That Threads sign-in expired or did not start here. Please try again.'));
        }

        $team = $request->user()->teams()->whereKey($flow['team_id'])->first();
        abort_unless($team && $request->user()->can('manage', $team), 403);

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

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('connections.index');
    }
}
