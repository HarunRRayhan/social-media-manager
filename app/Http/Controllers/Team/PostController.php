<?php

namespace App\Http\Controllers\Team;

use App\Actions\TakeDownPost;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PostController extends Controller
{
    /**
     * Take a published post down from the platform; the post stays on the calendar as deleted.
     */
    public function destroy(Request $request, int $post): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $model = $team->posts()->where('status', 'published')->findOrFail($post);
        $errors = TakeDownPost::run($model);

        Inertia::flash('toast', $errors === []
            ? ['type' => 'success', 'message' => __('Post deleted from the platform.')]
            : ['type' => 'error', 'message' => implode(' ', $errors)]);

        return back();
    }

    /**
     * Put a post into one list (appended), or take it out of every list with a null list_id.
     */
    public function updateList(Request $request, int $post): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $model = $team->posts()->findOrFail($post);
        $data = $request->validate([
            'list_id' => ['nullable', 'integer', Rule::exists('post_lists', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ]);

        $model->lists->each(fn ($list) => $list->removePost($model));

        if ($data['list_id']) {
            $team->postLists()->findOrFail((int) $data['list_id'])->addPost($model);
        }

        return back();
    }
}
