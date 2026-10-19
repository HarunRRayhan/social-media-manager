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
 * @property string $type
 * @property string $name
 * @property list<string>|null $post_remote_ids
 * @property list<string> $keywords
 * @property string $dm_message
 * @property string|null $public_reply
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'name', 'post_remote_ids', 'keywords', 'dm_message', 'public_reply', 'active'])]
class CommentAutomation extends Model
{
    /** The automation types a team can create; the page lists more as coming soon. */
    public const TYPES = ['comment_to_dm'];

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

    /**
     * The keyword of this automation that would answer the same comments as these settings, if any: Meta allows
     * one private reply per comment, so two automations sharing a post and a keyword would fight over it.
     * Keywords clash when one contains the other, because matching is by "contains", ignoring case.
     *
     * @param  list<string>  $keywords
     * @param  list<string>|null  $posts
     */
    public function clashingKeyword(array $keywords, ?array $posts): ?string
    {
        if ($this->post_remote_ids !== null && $posts !== null && array_intersect($this->post_remote_ids, $posts) === []) {
            return null;
        }

        foreach ($this->keywords as $mine) {
            foreach ($keywords as $theirs) {
                if ($mine !== '' && $theirs !== '' && (mb_stripos($mine, $theirs) !== false || mb_stripos($theirs, $mine) !== false)) {
                    return $theirs;
                }
            }
        }

        return null;
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
