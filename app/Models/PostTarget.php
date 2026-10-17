<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One account a post goes out to, so a partial failure shows which account failed.
 *
 * @property int $id
 * @property int $post_id
 * @property int|null $social_account_id
 * @property string $status
 * @property string|null $remote_id
 * @property string|null $remote_url
 * @property string|null $error
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['social_account_id', 'status', 'remote_id', 'remote_url', 'error', 'published_at'])]
class PostTarget extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
