<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApiTokenStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    /**
     * Show the user's API tokens.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/api-tokens', [
            'newApiToken' => $request->session()->get('newApiToken'),
            'tokens' => $request->user()
                ->tokens()
                ->latest('id')
                ->get(['id', 'name', 'created_at', 'last_used_at'])
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at_diff' => $token->created_at->diffForHumans(),
                    'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Create a named API token. The plaintext is flashed once and never stored.
     */
    public function store(ApiTokenStoreRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken($request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token created.')]);

        return to_route('api-tokens.edit')->with('newApiToken', $token->plainTextToken);
    }

    /**
     * Revoke one of the user's API tokens.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->findOrFail($token)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('api-tokens.edit');
    }
}
