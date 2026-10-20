<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    /**
     * Show the current team's connected accounts and the sets each one posts for.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam();
        $canManage = $user->can('manage', $team);

        return Inertia::render('team/connections', [
            'accounts' => $team->socialAccounts()->with('socialSets:id')->orderBy('provider')->orderBy('name')->get()
                ->map(fn (SocialAccount $account) => [
                    ...$account->only('id', 'provider', 'name', 'username', 'avatar_url'),
                    'social_set_ids' => $account->socialSets->pluck('id'),
                    // A token that expires within a week (or already has) and was not renewed needs a fresh sign-in.
                    'reconnect' => $account->token_expires_at?->lte(now()->addDays(7)) ?? false,
                ]),
            'socialSets' => $team->socialSets()->orderBy('name')->get(['id', 'name']),
            'canManage' => $canManage,
            'metaConfigured' => filled(config('services.meta.app_id')),
            'threadsConfigured' => filled(config('services.threads.app_id')),
            'linkedinConfigured' => filled(config('services.linkedin.client_id')),
            // A Facebook sign-in waiting for the user to pick its accounts; opens the picker dialog.
            'pending' => $canManage ? MetaConnectionController::candidates($request, $team) : null,
        ]);
    }

    /**
     * Choose which of the team's sets an account posts for.
     */
    public function update(Request $request, int $account): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $model = $team->socialAccounts()->findOrFail($account);
        $ids = $request->validate([
            'social_set_ids' => ['present', 'array'],
            'social_set_ids.*' => ['integer', Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ])['social_set_ids'];

        // Keep links to deleted sets, so restoring a set brings its accounts back.
        $model->socialSets()->sync([...$ids, ...$model->socialSets()->onlyTrashed()->pluck('social_sets.id')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Social sets updated.')]);

        return to_route('connections.index');
    }

    /**
     * Disconnect an account. Its sets lose it; past posts keep their results.
     */
    public function destroy(Request $request, int $account): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->socialAccounts()->findOrFail($account)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account disconnected.')]);

        return to_route('connections.index');
    }
}
