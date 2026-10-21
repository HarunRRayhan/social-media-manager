<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\SocialSet;
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
            'socialSets' => $team->socialSets()->withCount('posts')->orderBy('name')->get(['id', 'name', 'is_default']),
            'deletedSocialSets' => $team->socialSets()->onlyTrashed()->withCount('posts')->latest('deleted_at')->get()
                ->map(fn ($set) => [
                    'id' => $set->id,
                    'name' => $set->name,
                    'posts_count' => $set->posts_count,
                    'purges_on' => $set->deleted_at->addDays(SocialSet::RESTORE_DAYS)->toFormattedDateString(),
                ]),
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
     * Move a set and its posts to the 30-day bin, and revoke the tokens limited to it. The Default set stays.
     */
    public function destroy(Request $request, int $socialSet): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $set = $team->socialSets()->findOrFail($socialSet);

        if ($set->is_default) {
            throw ValidationException::withMessages(['social_set' => __('The Default set cannot be deleted.')]);
        }

        DB::transaction(function () use ($team, $set) {
            // Abilities are stored as a JSON list, and a set token always holds exactly this one.
            $team->tokens()->where('abilities', json_encode([$set->tokenAbility()]))->delete();
            $set->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Social set deleted. You can restore it for :days days.', ['days' => SocialSet::RESTORE_DAYS])]);

        return to_route('social-sets.index');
    }

    /**
     * Bring back a deleted set with its posts. Tokens revoked at deletion stay revoked.
     */
    public function restore(Request $request, int $socialSet): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->socialSets()->onlyTrashed()->findOrFail($socialSet)->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Social set restored.')]);

        return to_route('social-sets.index');
    }
}
