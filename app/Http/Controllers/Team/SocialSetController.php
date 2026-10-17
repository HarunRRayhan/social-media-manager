<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SocialSetController extends Controller
{
    /**
     * Show the current team's social sets with their post counts.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam();

        return Inertia::render('team/social-sets', [
            'socialSets' => $team->socialSets()->withCount('posts')->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->can('manage', $team),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->socialSets()->create($request->validate(['name' => ['required', 'string', 'max:255']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Social set created.')]);

        return to_route('social-sets.index');
    }

    public function update(Request $request, int $socialSet): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->socialSets()->findOrFail($socialSet)->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Social set renamed.')]);

        return to_route('social-sets.index');
    }

    /**
     * Delete a set with its posts, and revoke the tokens limited to it. A team keeps at least one set.
     */
    public function destroy(Request $request, int $socialSet): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $set = $team->socialSets()->findOrFail($socialSet);

        if ($team->socialSets()->count() === 1) {
            throw ValidationException::withMessages(['social_set' => __('A team needs at least one social set.')]);
        }

        DB::transaction(function () use ($team, $set) {
            // Abilities are stored as a JSON list, and a set token always holds exactly this one.
            $team->tokens()->where('abilities', json_encode([$set->tokenAbility()]))->delete();
            $set->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Social set deleted.')]);

        return to_route('social-sets.index');
    }
}
