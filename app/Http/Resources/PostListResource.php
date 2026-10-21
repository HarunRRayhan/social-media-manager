<?php

namespace App\Http\Resources;

use App\Models\PostList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PostList
 */
class PostListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'social_set_id' => $this->social_set_id,
            /** Platform-side mirror ids keyed by platform (e.g. a YouTube playlist); empty until a mirror exists. */
            'remote_ids' => $this->remote_ids ?? (object) [],
            'posts_count' => $this->whenCounted('posts'),
            /** In list order; each post's `lists` entry for this list carries its position. */
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
