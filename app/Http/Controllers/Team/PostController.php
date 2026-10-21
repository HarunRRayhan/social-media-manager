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
     * Append a post to a list it is not in yet; its other lists are untouched.
     */
    public function updateList(Request $request, int $post): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $model = $team->posts()->findOrFail($post);
        $data = $request->validate([
            'list_id' => ['required', 'integer', Rule::exists('post_lists', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ]);

        $team->postLists()->findOrFail((int) $data['list_id'])->addPost($model);

        return back();
    }
}
