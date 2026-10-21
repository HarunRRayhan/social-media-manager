<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DeletePost;
use App\Actions\SavePost;
use App\Concerns\TokenScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexPostRequest;
use App\Http\Requests\Api\V1\StorePostRequest;
use App\Http\Requests\Api\V1\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PostController extends Controller
{
    use TokenScope;

    /**
     * List the token's posts (the team's, or one social set's), newest first, optionally filtered by status, platform,
     * social set, and the date range (`from`/`to`) they go out in.
     */
    public function index(IndexPostRequest $request): AnonymousResourceCollection
    {
        $posts = $this->visiblePosts($request)
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->validated('platform'), fn ($query, $platform) => $query->where('platform', $platform))
            ->when($request->validated('social_set_id'), fn ($query, $set) => $query->where('social_set_id', $set))
            // A post sits on the day it goes out: its schedule, else when it published, else when it was written.
            ->when($request->validated('from'), fn ($query, $from) => $query->whereRaw('COALESCE(scheduled_at, published_at, created_at) >= ?', [$from]))
            ->when($request->validated('to'), fn ($query, $to) => $query->whereRaw('COALESCE(scheduled_at, published_at, created_at) <= ?', [$to]))
            ->with('targets.socialAccount', 'lists')
            ->latest('id')
            ->paginate();

        return PostResource::collection($posts);
    }

    /**
     * Create a post. Without scheduled_at it is saved as a draft; with it, it is scheduled. An optional `list_id`
     * (+ 1-based `position`, default: appended) puts it into a list.
     *
     * A whole-team token must name the social_set_id; a social-set token posts to its own set.
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $post = SavePost::create($this->team($request), $this->tokenSocialSet($request), $request->validated());

        return PostResource::make($post)->response()->setStatusCode(201);
    }

    /**
     * Show one of the token's posts.
     */
    public function show(Request $request, int $post): PostResource
    {
        return PostResource::make($this->ownedPost($request, $post));
    }

    /**
     * Update a draft or scheduled post. Its status is recomputed from scheduled_at. `list_id` (+ `position`) adds
     * it to a list or moves it there.
     */
    public function update(UpdatePostRequest $request, int $post): PostResource|JsonResponse
    {
        $model = $this->ownedPost($request, $post);

        if (! $model->isEditable()) {
            return response()->json(['message' => 'Only draft or scheduled posts can be edited.'], 409);
        }

        return PostResource::make(SavePost::update($model, $request->validated()));
    }

    /**
     * Delete a post. A draft or scheduled one is removed (204). A published one is taken down from every
     * account it went out to and kept with status `deleted` (200). If an account cannot delete it, the
     * answer is 422 with what to do in `message`: reconnect the account, or delete it in the platform's app.
     * Not 502: Cloudflare replaces a 502 body with its own page, so the message would never arrive.
     */
    public function destroy(Request $request, int $post): PostResource|Response
    {
        $takenDown = DeletePost::run($this->ownedPost($request, $post));

        return $takenDown ? PostResource::make($takenDown) : response()->noContent();
    }

    /**
     * Posts outside the token's team or social set 404 exactly like missing ones.
     */
    private function ownedPost(Request $request, int $post): Post
    {
        return $this->visiblePosts($request)->findOrFail($post);
    }
}
