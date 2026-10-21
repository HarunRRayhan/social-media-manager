<?php

namespace App\Concerns;

use App\Models\Post;
use App\Models\PostList;
use App\Models\SocialSet;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Mcp\Request as McpRequest;

/**
 * What an API token reaches: its team, and optionally just one of the team's social sets.
 * Works for HTTP requests and MCP tool requests alike; both authenticate as the team.
 */
trait TokenScope
{
    protected function team(Request|McpRequest $request): Team
    {
        /** @var Team */
        return $request->user();
    }

    /**
     * The set a social-set token is limited to, or null for a whole-team token.
     */
    protected function tokenSocialSet(Request|McpRequest $request): ?SocialSet
    {
        return $this->team($request)->tokenSocialSet();
    }

    /**
     * @return Builder<SocialSet>
     */
    protected function visibleSocialSets(Request|McpRequest $request): Builder
    {
        $set = $this->tokenSocialSet($request);

        return $this->team($request)->socialSets()->getQuery()->when($set, fn ($query) => $query->whereKey($set->id));
    }

    /**
     * @return Builder<Post>
     */
    protected function visiblePosts(Request|McpRequest $request): Builder
    {
        $set = $this->tokenSocialSet($request);

        return $this->team($request)->posts()->getQuery()->whereHas('socialSet')->when($set, fn ($query) => $query->whereBelongsTo($set));
    }

    /**
     * Team-wide lists and, for a set token, only the lists of its own set.
     *
     * @return Builder<PostList>
     */
    protected function visibleLists(Request|McpRequest $request): Builder
    {
        $set = $this->tokenSocialSet($request);

        return $this->team($request)->postLists()->getQuery()->tap($this->team($request)->visibleListsScope());
    }

    /**
     * A list with its posts in order, only the ones the token can see.
     */
    protected function loadVisibleListPosts(Request|McpRequest $request, PostList $list): PostList
    {
        $team = $this->team($request);

        return $list->load([
            'posts' => $team->visiblePostsScope(),
            'posts.targets.socialAccount',
            'posts.lists' => $team->visibleListsScope(),
        ]);
    }
}
