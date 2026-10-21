<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Models\PostList;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'social_set_id' => $this->social_set_id,
            'platform' => $this->platform,
            'body' => $this->body,
            'media' => $this->media,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'error' => $this->error,
            /** One entry per connected account the post went out to; empty until it is published. */
            'targets' => PostTargetResource::collection($this->targets),
            /** Every list the post is in, with its 1-based position ("Part N") there. */
            'lists' => $this->visibleLists($request)->map(fn ($list) => ['id' => $list->id, 'name' => $list->name, 'position' => $list->pivot->position]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * The post's lists the caller may see: all of them for a user or whole-team token, team-wide ones plus its own
     * set's for a set-limited token. Reuses an eager load when there is one.
     *
     * @return Collection<int, PostList>
     */
    private function visibleLists(Request $request): Collection
    {
        // Resources render for the API and MCP only, both authenticated as the team through Sanctum.
        $team = Auth::guard('sanctum')->user();

        if (! $team instanceof Team) {
            return $this->lists;
        }

        return $this->relationLoaded('lists') && ! $team->tokenSocialSet()
            ? $this->lists
            : $this->lists()->tap($team->visibleListsScope())->get();
    }
}
