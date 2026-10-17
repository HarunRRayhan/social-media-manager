<?php

namespace App\Http\Resources;

use App\Models\PostTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PostTarget
 */
class PostTargetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'social_account_id' => $this->social_account_id,
            // Null once the account has been disconnected.
            'account' => $this->socialAccount ? $this->socialAccount->only('name', 'provider') : null,
            /** @var 'pending'|'published'|'failed' */
            'status' => $this->status,
            'remote_id' => $this->remote_id,
            'remote_url' => $this->remote_url,
            'error' => $this->error,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
