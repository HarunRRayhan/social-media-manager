<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\CommentAutomation;
use App\Models\CommentAutomationRun;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Comment-to-DM automations for the current team's Facebook Pages and Instagram accounts.
 */
class CommentAutomationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam();

        return Inertia::render('team/automations', [
            'automations' => $team->commentAutomations()->with('socialAccount:id,provider,name')->latest()->get()
                ->map(fn (CommentAutomation $automation) => [
                    ...$automation->only('id', 'social_account_id', 'name', 'post_remote_id', 'keywords', 'dm_message', 'public_reply', 'active'),
                    'account' => $automation->socialAccount->only('provider', 'name'),
                    'runs' => $automation->runs()->latest('id')->limit(5)->get()
                        ->map(fn (CommentAutomationRun $run) => [
                            ...$run->only('id', 'comment_id', 'status', 'error'),
                            'created_at' => $run->created_at?->toDayDateTimeString(),
                        ]),
                ]),
            'accounts' => $team->socialAccounts()->orderBy('provider')->orderBy('name')->get(['id', 'provider', 'name']),
            'canManage' => $user->can('manage', $team),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->commentAutomations()->create($this->validated($request, $team));

        return $this->back(__('Automation created.'));
    }

    public function update(Request $request, int $automation): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->commentAutomations()->findOrFail($automation)->update($this->validated($request, $team));

        return $this->back(__('Automation saved.'));
    }

    public function destroy(Request $request, int $automation): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->commentAutomations()->findOrFail($automation)->delete();

        return $this->back(__('Automation deleted.'));
    }

    /**
     * Subscribe every connected Page to the app's webhooks, for teams connected before automations existed
     * or whose subscription failed at connect time.
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $failed = $team->socialAccounts()->where('provider', 'facebook')->get()
            ->filter(function ($page) {
                try {
                    MetaConnectionController::subscribe($page->external_id, $page->access_token);

                    return false;
                } catch (RuntimeException) {
                    return true;
                }
            })
            ->pluck('name');

        Inertia::flash('toast', $failed->isEmpty()
            ? ['type' => 'success', 'message' => __('Comment automation is enabled for every connected Page.')]
            : ['type' => 'error', 'message' => __('Could not enable it for: :pages. Reconnect them and try again.', ['pages' => $failed->implode(', ')])]);

        return to_route('automations.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Team $team): array
    {
        return $request->validate([
            'social_account_id' => ['required', 'integer', Rule::exists('social_accounts', 'id')->where('team_id', $team->id)],
            'name' => ['required', 'string', 'max:255'],
            'post_remote_id' => ['nullable', 'string', 'max:255'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:100'],
            'dm_message' => ['required', 'string', 'max:1000'],
            'public_reply' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ]);
    }

    private function back(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('automations.index');
    }
}
