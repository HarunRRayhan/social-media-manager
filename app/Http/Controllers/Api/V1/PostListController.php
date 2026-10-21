<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\TokenScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddListPostRequest;
use App\Http\Requests\Api\V1\ReorderListRequest;
use App\Http\Requests\Api\V1\StorePostListRequest;
use App\Http\Requests\Api\V1\UpdatePostListRequest;
use App\Http\Resources\PostListResource;
use App\Models\PostList;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PostListController extends Controller
{
    use TokenScope;

    /**
     * The token's lists: the team's, or (for a set token) team-wide ones plus its own set's.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return PostListResource::collection($this->visibleLists($request)->withCount(['posts' => $this->team($request)->visiblePostsScope()])->orderBy('id')->get());
    }

    /**
     * Create a list. A set token's list belongs to its own set.
     */
    public function store(StorePostListRequest $request): JsonResponse
    {
        $tokenSet = $this->tokenSocialSet($request);
        $data = $request->validated();
        $data['social_set_id'] ??= $tokenSet?->id;

        if ($tokenSet && (int) $data['social_set_id'] !== $tokenSet->id) {
            throw new AuthorizationException('This token can only create lists in its own social set.');
        }

        $list = $this->team($request)->postLists()->create($data);

        return PostListResource::make($list->loadCount(['posts' => $this->team($request)->visiblePostsScope()]))->response()->setStatusCode(201);
    }

    /**
     * Show a list with its posts in order.
     */
    public function show(Request $request, int $list): PostListResource
    {
        return PostListResource::make($this->loadVisibleListPosts($request, $this->ownedList($request, $list)));
    }

    /**
     * Rename or describe a list, or move it to another set. A set token cannot move it out of its own set.
     */
    public function update(UpdatePostListRequest $request, int $list): PostListResource
    {
        $tokenSet = $this->tokenSocialSet($request);
        $data = $request->validated();

        if ($tokenSet && array_key_exists('social_set_id', $data) && (int) $data['social_set_id'] !== $tokenSet->id) {
            throw new AuthorizationException('This token can only keep lists in its own social set.');
        }

        $model = $this->ownedList($request, $list);
        $model->update($data);

        return PostListResource::make($model->loadCount(['posts' => $this->team($request)->visiblePostsScope()]));
    }

    /**
     * Soft-delete a list. Its posts are untouched.
     */
    public function destroy(Request $request, int $list): Response
    {
        $this->ownedList($request, $list)->delete();

        return response()->noContent();
    }

    /**
     * Add one of the token's posts at a 1-based position (appended by default); a post already in the list moves there.
     */
    public function addPost(AddListPostRequest $request, int $list): PostListResource
    {
        $model = $this->ownedList($request, $list);
        $post = $this->visiblePosts($request)->findOrFail((int) $request->validated('post_id'));
        $model->addPost($post, $request->filled('position') ? (int) $request->validated('position') : null);

        return $this->show($request, $list);
    }

    /**
     * Remove a post from the list; the posts after it move up.
     */
    public function removePost(Request $request, int $list, int $post): Response
    {
        $model = $this->ownedList($request, $list);
        $model->removePost($this->visiblePosts($request)->findOrFail($post));

        return response()->noContent();
    }

    /**
     * Replace the order with `post_ids`, which must name every post of the list exactly once.
     */
    public function reorder(ReorderListRequest $request, int $list): PostListResource
    {
        $this->ownedList($request, $list)->reorder(array_map('intval', $request->validated('post_ids')));

        return $this->show($request, $list);
    }

    private function ownedList(Request $request, int $list): PostList
    {
        return $this->visibleLists($request)->findOrFail($list);
    }
}
