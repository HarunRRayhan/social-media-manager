<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sends a DM (and optionally a public reply) to whoever comments one of its keywords on an account's posts.
 *
 * @property int $id
 * @property int $team_id
 * @property int $social_account_id
 * @property string $name
 * @property string|null $post_remote_id
 * @property list<string> $keywords
 * @property string $dm_message
 * @property string|null $public_reply
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['social_account_id', 'name', 'post_remote_id', 'keywords', 'dm_message', 'public_reply', 'active'])]
class CommentAutomation extends Model
{
    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'active' => 'boolean',
        ];
    }

    /** Whether the comment holds any keyword, ignoring case. */
    public function matches(string $text): bool
    {
        return collect($this->keywords)->contains(fn (string $keyword) => mb_stripos($text, $keyword) !== false);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /** @return HasMany<CommentAutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(CommentAutomationRun::class);
    }
}
