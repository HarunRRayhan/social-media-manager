<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * API tokens belong to the team, so the API authenticates as the team (hence Authenticatable).
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Team extends Model implements AuthenticatableContract
{
    /** @use HasFactory<TeamFactory> */
    use Authenticatable, HasApiTokens, HasFactory;

    protected static function booted(): void
    {
        // Tokens are a morph relation, so no foreign key cascades them.
        static::deleting(fn (Team $team) => $team->tokens()->delete());
    }

    /**
     * Create a team owned by the user, with one "Default" set, and make it their current team.
     */
    public static function createFor(User $user, string $name): self
    {
        return DB::transaction(function () use ($user, $name) {
            $team = self::create(['name' => $name]);
            $team->users()->attach($user, ['role' => 'owner']);
            $team->socialSets()->create(['name' => 'Default']);
            $user->forceFill(['current_team_id' => $team->id])->save();

            return $team;
        });
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<SocialSet, $this> */
    public function socialSets(): HasMany
    {
        return $this->hasMany(SocialSet::class);
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }
}
