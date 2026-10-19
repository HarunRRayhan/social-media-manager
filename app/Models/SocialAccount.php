<?php

namespace App\Models;

use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A connected Facebook Page or Instagram professional account, owned by a team and usable in any of its sets.
 *
 * @property int $id
 * @property int $team_id
 * @property string $provider
 * @property string $external_id
 * @property string $name
 * @property string|null $username
 * @property string|null $avatar_url
 * @property string $access_token
 * @property Carbon|null $token_expires_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'provider', 'external_id', 'name', 'username', 'avatar_url', 'access_token', 'token_expires_at', 'meta'])]
#[Hidden(['access_token'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsToMany<SocialSet, $this> */
    public function socialSets(): BelongsToMany
    {
        return $this->belongsToMany(SocialSet::class);
    }

    /** @return BelongsToMany<CommentAutomation, $this> */
    public function commentAutomations(): BelongsToMany
    {
        return $this->belongsToMany(CommentAutomation::class);
    }
}
