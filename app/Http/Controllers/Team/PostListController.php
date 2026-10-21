<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current team's lists: ordered groups of posts, such as the parts of a series.
 */
class PostListController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam();

        return Inertia::render('team/lists', [
            'lists' => $team->postLists()->withCount('posts')->with('socialSet:id,name')->orderBy('name')->get()
                ->map(fn (PostList $list) => [
                    'id' => $list->id,
                    'name' => $list->name,
                    'description' => $list->description,
                    'social_set_id' => $list->social_set_id,
                    'social_set' => $list->socialSet?->name,
                    'posts_count' => $list->posts_count,
                ]),
            'socialSets' => $team->socialSets()->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->can('manage', $team),
        ]);
    }

    public function show(Request $request, int $list): Response
    {
        $user = $request->user();
        $team = $user->currentTeam();
        $model = $team->postLists()->with('posts.socialSet:id,name')->findOrFail($list);

        return Inertia::render('team/list', [
            'list' => [
                'id' => $model->id,
                'name' => $model->name,
                'description' => $model->description,
                'posts' => $model->posts->map(fn (Post $post) => [
                    'id' => $post->id,
                    'position' => $post->pivot->position,
                    'platform' => $post->platform,
                    'status' => $post->status,
                    'preview' => Str::limit((string) $post->body, 80),
                    'at' => ($post->scheduled_at ?? $post->published_at ?? $post->created_at)->toIso8601String(),
                    'social_set' => $post->socialSet?->name,
                ]),
            ],
            'canManage' => $user->can('manage', $team),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $list = $team->postLists()->create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List created.')]);

        return to_route('lists.show', $list);
    }

    public function update(Request $request, int $list): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->postLists()->findOrFail($list)->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List saved.')]);

        return back();
    }

    /**
     * Soft-delete the list. Its posts stay where they are.
     */
    public function destroy(Request $request, int $list): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->postLists()->findOrFail($list)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('List deleted.')]);

        return to_route('lists.index');
    }

    public function removePost(Request $request, int $list, int $post): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->postLists()->findOrFail($list)->removePost($team->posts()->findOrFail($post));

        return back();
    }

    /**
     * Replace the order; `post_ids` must name every post of the list exactly once.
     */
    public function reorder(Request $request, int $list): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $data = $request->validate(['post_ids' => ['required', 'array'], 'post_ids.*' => ['integer', 'distinct']]);
        $team->postLists()->findOrFail($list)->reorder(array_map('intval', $data['post_ids']));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $team = $request->user()->currentTeam();

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'social_set_id' => ['nullable', 'integer', Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ]);
    }
}
