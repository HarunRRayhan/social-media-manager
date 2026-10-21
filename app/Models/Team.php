<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use DateTimeInterface;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

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
            $team->socialSets()->create(['name' => 'Default', 'is_default' => true]);
            $user->forceFill(['current_team_id' => $team->id])->save();

            return $team;
        });
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Issue a token that says what it is, Stripe-style: sm_{team|set}_{live|test}_<random>.
     * Without the default "id|" part, Sanctum finds it by its hash alone.
     *
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $kind = $abilities === ['*'] ? 'team' : 'set';
        $mode = app()->isProduction() ? 'live' : 'test';
        $plainTextToken = "sm_{$kind}_{$mode}_".Str::random(48);

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $plainTextToken);
    }

    /**
     * Typed to the app's token model, which knows who created each token.
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    /** @return HasMany<SocialSet, $this> */
    public function socialSets(): HasMany
    {
        return $this->hasMany(SocialSet::class);
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /** @return HasMany<CommentAutomation, $this> */
    public function commentAutomations(): HasMany
    {
        return $this->hasMany(CommentAutomation::class);
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<PostList, $this> */
    public function postLists(): HasMany
    {
        return $this->hasMany(PostList::class);
    }

    /**
     * The set the current API token is limited to, or null for a whole-team token.
     */
    public function tokenSocialSet(): ?SocialSet
    {
        $ability = collect($this->currentAccessToken()->abilities)
            ->first(fn (string $ability) => Str::startsWith($ability, 'social-set:'));

        return $ability ? $this->socialSets()->findOrFail((int) Str::after($ability, 'social-set:')) : null;
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }
}
