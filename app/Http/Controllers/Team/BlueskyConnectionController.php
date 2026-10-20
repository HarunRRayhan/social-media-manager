<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Publishers\Bluesky;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Bluesky has no OAuth app to register: the user gives a handle and an app password, which is checked
 * by signing in once and then kept encrypted, so every publish can sign in again.
 */
class BlueskyConnectionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $input = $request->validate([
            'handle' => ['required', 'string', 'max:253'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        try {
            $session = Bluesky::session(ltrim(trim($input['handle']), '@'), $input['password']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['password' => __('Bluesky did not accept that handle and app password.')]);
        }

        $team->socialAccounts()->updateOrCreate(
            ['provider' => 'bluesky', 'external_id' => $session['did']],
            ['name' => $session['handle'], 'username' => $session['handle'], 'access_token' => $input['password'], 'token_expires_at' => null],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected 1 account.')]);

        return to_route('connections.index');
    }
}
