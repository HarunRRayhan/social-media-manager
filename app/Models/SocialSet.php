<?php

namespace App\Models;

use Database\Factories\SocialSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name'])]
class SocialSet extends Model
{
    /** @use HasFactory<SocialSetFactory> */
    use HasFactory, SoftDeletes;

    /** Days a deleted set (and its posts) can still be restored before it is purged. */
    public const RESTORE_DAYS = 30;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return BelongsToMany<SocialAccount, $this> */
    public function socialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class);
    }

    /** The Sanctum ability that limits a token to this set. */
    public function tokenAbility(): string
    {
        return "social-set:{$this->id}";
    }
}
