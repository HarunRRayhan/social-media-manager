<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Teams the user owns go with them; teams they only belong to stay.
        static::deleting(fn (User $user) => $user->teams()->wherePivot('role', 'owner')->get()->each->delete());
    }

    /**
     * Create a verified account with its personal team. The password is never shown or used;
     * the random value only fills the column.
     *
     * @param  array<string, string>  $attributes
     */
    public static function register(array $attributes): self
    {
        $user = self::create([...$attributes, 'password' => bin2hex(random_bytes(32))]);
        $user->markEmailAsVerified();
        $user->createPersonalTeam();

        event(new Registered($user));

        return $user;
    }

    public function createPersonalTeam(): Team
    {
        return Team::createFor($this, "{$this->name}'s Team");
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /**
     * The team the user is working in, with its pivot role. Falls back to another of their
     * teams, or a new personal one, so a signed-in user always has a current team.
     */
    public function currentTeam(): Team
    {
        $team = $this->teams()->find($this->current_team_id) ?? $this->teams()->oldest('teams.id')->first();

        if (! $team) {
            $this->createPersonalTeam();
            $team = $this->teams()->sole();
        }

        if ($team->id !== $this->current_team_id) {
            $this->forceFill(['current_team_id' => $team->id])->save();
        }

        return $team;
    }

    public function teamRole(Team $team): ?string
    {
        return $this->teams()->whereKey($team->id)->first()?->getRelation('pivot')->role;
    }
}
