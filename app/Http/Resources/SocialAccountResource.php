<?php

namespace App\Http\Resources;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SocialAccount
 */
class SocialAccountResource extends JsonResource
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
            /** @var 'facebook'|'instagram'|'threads'|'bluesky'|'linkedin' */
            'provider' => $this->provider,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            /** @var array<int, int> */
            'social_set_ids' => $this->socialSets->pluck('id')->all(),
        ];
    }
}
