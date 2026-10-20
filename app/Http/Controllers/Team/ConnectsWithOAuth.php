<?php

namespace App\Http\Controllers\Team;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The parts every OAuth sign-in for a team shares: a CSRF state tied to the team in the session,
 * checked again on the way back, and a toast on the Connections page.
 */
trait ConnectsWithOAuth
{
    /**
     * Remember a new sign-in for the current team (admins only) and return its state.
     */
    private function startOAuth(Request $request, string $key): string
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $state = Str::random(40);
        $request->session()->put($key, ['state' => $state, 'team_id' => $team->id]);

        return $state;
    }

    /**
     * The team a returning sign-in was started for, or null when its state is missing or wrong.
     */
    private function finishOAuth(Request $request, string $key): ?Team
    {
        $flow = $request->session()->pull($key);

        if (! $flow || ! is_string($request->query('state')) || ! hash_equals($flow['state'], $request->query('state'))) {
            return null;
        }

        $team = $request->user()->teams()->whereKey($flow['team_id'])->first();
        abort_unless($team && $request->user()->can('manage', $team), 403);

        return $team;
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('connections.index');
    }
}
