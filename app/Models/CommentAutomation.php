<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sends a DM (and optionally a public reply) to whoever comments one of its keywords on its accounts' posts.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property list<string>|null $post_remote_ids
 * @property list<string> $keywords
 * @property string $dm_message
 * @property string|null $public_reply
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'post_remote_ids', 'keywords', 'dm_message', 'public_reply', 'active'])]
class CommentAutomation extends Model
{
    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'post_remote_ids' => 'array',
            'active' => 'boolean',
        ];
    }

    /** Whether the comment holds any keyword, ignoring case. */
    public function matches(string $text): bool
    {
        return collect($this->keywords)->contains(fn (string $keyword) => mb_stripos($text, $keyword) !== false);
    }

    /** Whether the automation listens on this post: no list means every post. */
    public function coversPost(string $postId): bool
    {
        return $this->post_remote_ids === null || in_array($postId, $this->post_remote_ids, true);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsToMany<SocialAccount, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class);
    }

    /** @return HasMany<CommentAutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(CommentAutomationRun::class);
    }
}
