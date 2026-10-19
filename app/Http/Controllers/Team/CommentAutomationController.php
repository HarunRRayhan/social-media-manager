<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\CommentAutomation;
use App\Models\CommentAutomationRun;
use App\Models\PostTarget;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
            'automations' => $team->commentAutomations()->with('accounts:id,provider,name')->latest()->get()
                ->map(fn (CommentAutomation $automation) => [
                    ...$automation->only('id', 'type', 'name', 'post_remote_ids', 'keywords', 'dm_message', 'public_reply', 'active'),
                    'social_account_ids' => $automation->accounts->pluck('id'),
                    'accounts' => $automation->accounts->map->only('id', 'provider', 'name'),
                    'runs' => $automation->runs()->latest('id')->limit(5)->get()
                        ->map(fn (CommentAutomationRun $run) => [
                            ...$run->only('id', 'comment_id', 'status', 'error'),
                            'created_at' => $run->created_at?->toDayDateTimeString(),
                        ]),
                ]),
            'accounts' => $team->socialAccounts()->orderBy('provider')->orderBy('name')->get(['id', 'provider', 'name']),
            // The newest published posts, for scoping an automation to some of them.
            'posts' => PostTarget::query()
                ->where('status', 'published')
                ->whereNotNull('remote_id')
                ->whereIn('social_account_id', $team->socialAccounts()->select('id'))
                ->with(['post:id,body', 'socialAccount:id,provider,name'])
                ->latest('published_at')
                ->limit(50)
                ->get()
                ->map(fn (PostTarget $target) => [
                    'remote_id' => $target->remote_id,
                    'social_account_id' => $target->social_account_id,
                    'provider' => $target->socialAccount->provider,
                    'account' => $target->socialAccount->name,
                    'excerpt' => Str::limit((string) $target->post->body, 80),
                    'published_at' => $target->published_at?->toFormattedDateString(),
                ]),
            'canManage' => $user->can('manage', $team),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $data = $this->validated($request, $team);

        DB::transaction(fn () => $team->commentAutomations()->create(Arr::except($data, 'social_account_ids'))
            ->accounts()->sync($data['social_account_ids']));

        return $this->back(__('Automation created.'));
    }

    public function update(Request $request, int $automation): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $automation = $team->commentAutomations()->findOrFail($automation);
        $data = $this->validated($request, $team);

        DB::transaction(function () use ($automation, $data) {
            $automation->update(Arr::except($data, 'social_account_ids'));
            $automation->accounts()->sync($data['social_account_ids']);
        });

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
     * Re-subscribe every connected Page (and each Instagram account's Page) to the app's webhooks.
     */
    public function webhooks(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        // Page id => Page token; an Instagram account carries its Page's id and token.
        $pages = $team->socialAccounts()->whereIn('provider', ['facebook', 'instagram'])->get()
            ->mapWithKeys(fn ($account) => [(string) ($account->provider === 'facebook' ? $account->external_id : ($account->meta['page_id'] ?? '')) => $account->access_token])
            ->filter(fn ($token, $pageId) => $pageId !== '');

        $failed = [];

        foreach ($pages as $pageId => $token) {
            try {
                MetaConnectionController::subscribe((string) $pageId, $token);
            } catch (RuntimeException $e) {
                $failed[] = $e->getMessage();
            }
        }

        if ($pages->isEmpty()) {
            return $this->back(__('No Facebook Page is connected.'), 'error');
        }

        return $failed === []
            ? $this->back(trans_choice('{1} Webhooks are on for 1 Page.|[2,*] Webhooks are on for :count Pages.', $pages->count()))
            : $this->back(__('Meta refused: :error', ['error' => implode(' ', array_unique($failed))]), 'error');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Team $team): array
    {
        $data = $request->validate([
            'social_account_ids' => ['required', 'array', 'min:1'],
            'social_account_ids.*' => ['required', 'integer', 'distinct', Rule::exists('social_accounts', 'id')->where('team_id', $team->id)],
            'type' => ['required', Rule::in(CommentAutomation::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'post_remote_ids' => ['nullable', 'array', 'min:1'],
            'post_remote_ids.*' => ['required', 'string', 'max:255', 'distinct'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:100'],
            'dm_message' => ['required', 'string', 'max:1000'],
            'public_reply' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ], ['post_remote_ids.min' => __('Pick at least one post, or choose All posts.')]);

        // Null means every post; picking posts but none is refused above.
        return [...$data, 'post_remote_ids' => $data['post_remote_ids'] ?? null];
    }

    private function back(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('automations.index');
    }
}
