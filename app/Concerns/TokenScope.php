<?php

namespace App\Concerns;

use App\Models\Post;
use App\Models\SocialSet;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What an API token reaches: its team, and optionally just one of the team's social sets.
 */
trait TokenScope
{
    protected function team(Request $request): Team
    {
        /** @var Team */
        return $request->user();
    }

    /**
     * The set a social-set token is limited to, or null for a whole-team token.
     */
    protected function tokenSocialSet(Request $request): ?SocialSet
    {
        $team = $this->team($request);
        $ability = collect($team->currentAccessToken()->abilities)
            ->first(fn (string $ability) => Str::startsWith($ability, 'social-set:'));

        return $ability ? $team->socialSets()->findOrFail((int) Str::after($ability, 'social-set:')) : null;
    }

    /**
     * @return Builder<SocialSet>
     */
    protected function visibleSocialSets(Request $request): Builder
    {
        $set = $this->tokenSocialSet($request);

        return $this->team($request)->socialSets()->getQuery()->when($set, fn ($query) => $query->whereKey($set->id));
    }

    /**
     * @return Builder<Post>
     */
    protected function visiblePosts(Request $request): Builder
    {
        $set = $this->tokenSocialSet($request);

        return $this->team($request)->posts()->getQuery()->whereHas('socialSet')->when($set, fn ($query) => $query->whereBelongsTo($set));
    }
}
