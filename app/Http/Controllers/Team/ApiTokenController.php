<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\ApiTokenStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    /**
     * Show the current team's API tokens.
     */
    public function edit(Request $request): Response
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $sets = $team->socialSets()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('team/api-tokens', [
            'newApiToken' => $request->session()->get('newApiToken'),
            'socialSets' => $sets,
            'tokens' => $team
                ->tokens()
                ->latest('id')
                ->with('creator:id,name')
                ->get(['id', 'name', 'abilities', 'created_by', 'created_at', 'last_used_at'])
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    // A deleted set revokes its tokens, so every set ability resolves to a name.
                    'scope' => $token->abilities === ['*']
                        ? __('Full team')
                        : $sets->first(fn ($set) => $token->can($set->tokenAbility()))?->name,
                    'created_by' => $token->creator?->name,
                    'created_at_diff' => $token->created_at->diffForHumans(),
                    'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Create a named API token for the full team or one social set. The plaintext is flashed once and never stored.
     */
    public function store(ApiTokenStoreRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $set = $request->filled('social_set_id') ? $team->socialSets()->findOrFail($request->integer('social_set_id')) : null;

        $token = $team->createToken($request->validated('name'), $set ? [$set->tokenAbility()] : ['*']);
        $token->accessToken->forceFill(['created_by' => $request->user()->id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created.')]);

        return to_route('api-tokens.edit')->with('newApiToken', $token->plainTextToken);
    }

    /**
     * Revoke one of the current team's API tokens.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->tokens()->findOrFail($token)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('api-tokens.edit');
    }
}
