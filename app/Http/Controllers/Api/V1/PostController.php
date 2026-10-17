<?php

namespace App\Http\Controllers\Api\V1;

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
     * List the token's posts (the team's, or one social set's), newest first, optionally filtered by status.
     */
    public function index(IndexPostRequest $request): AnonymousResourceCollection
    {
        $posts = $this->visiblePosts($request)
            ->when($request->validated('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate();

        return PostResource::collection($posts);
    }

    /**
     * Create a post. Without scheduled_at it is saved as a draft; with it, it is scheduled.
     *
     * A whole-team token must name the social_set_id; a social-set token posts to its own set.
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $tokenSet = $this->tokenSocialSet($request);
        $setId = $request->validated('social_set_id') ?? $tokenSet?->id;

        if ($tokenSet && $setId !== $tokenSet->id) {
            return response()->json(['message' => 'This token can only post to its own social set.'], 403);
        }

        $post = Post::create([
            ...$request->validated(),
            'team_id' => $this->team($request)->id,
            'social_set_id' => $setId,
            'status' => $request->filled('scheduled_at') ? 'scheduled' : 'draft',
        ]);

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
     * Update a draft or scheduled post. Its status is recomputed from scheduled_at.
     */
    public function update(UpdatePostRequest $request, int $post): PostResource|JsonResponse
    {
        $model = $this->ownedPost($request, $post);

        if (! $model->isEditable()) {
            return response()->json(['message' => 'Only draft or scheduled posts can be edited.'], 409);
        }

        $model->fill($request->validated());
        $model->status = $model->scheduled_at ? 'scheduled' : 'draft';
        $model->save();

        return PostResource::make($model);
    }

    /**
     * Delete a draft or scheduled post.
     */
    public function destroy(Request $request, int $post): Response|JsonResponse
    {
        $model = $this->ownedPost($request, $post);

        if (! $model->isEditable()) {
            return response()->json(['message' => 'Only draft or scheduled posts can be deleted.'], 409);
        }

        $model->delete();

        return response()->noContent();
    }

    /**
     * Posts outside the token's team or social set 404 exactly like missing ones.
     */
    private function ownedPost(Request $request, int $post): Post
    {
        return $this->visiblePosts($request)->findOrFail($post);
    }
}
