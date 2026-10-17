<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Create a team and switch to it.
     */
    public function store(Request $request): RedirectResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];

        Team::createFor($request->user(), $name);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created.')]);

        return to_route('team.edit');
    }

    /**
     * Show the current team's general settings.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam();

        return Inertia::render('team/general', [
            'team' => $team->only('id', 'name'),
            'canManage' => $user->can('manage', $team),
            'canDelete' => $user->can('delete', $team),
            'isOnlyTeam' => $user->teams()->count() === 1,
        ]);
    }

    /**
     * Rename the current team.
     */
    public function update(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('team.edit');
    }

    /**
     * Delete the current team with its sets, posts and tokens. A user keeps at least one team.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam();
        Gate::authorize('delete', $team);

        if ($user->teams()->count() === 1) {
            throw ValidationException::withMessages(['team' => __('You cannot delete your only team.')]);
        }

        $team->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('dashboard');
    }
}
