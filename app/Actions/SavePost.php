<?php

namespace App\Actions;

use App\Models\Post;
use App\Models\PostList;
use App\Models\SocialSet;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creating and editing posts, shared by the REST API and the MCP tools so the two cannot drift.
 * `$data` is already validated by StorePostRequest / UpdatePostRequest rules; `list_id` and `position`
 * put the post into a list (appended when no position is given).
 */
class SavePost
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException when a set token names another set
     */
    public static function create(Team $team, ?SocialSet $tokenSet, array $data): Post
    {
        $setId = (int) ($data['social_set_id'] ?? $tokenSet?->id);

        if ($tokenSet && $setId !== $tokenSet->id) {
            throw new AuthorizationException('This token can only post to its own social set.');
        }

        return DB::transaction(function () use ($team, $setId, $data) {
            $post = Post::create([
                ...self::attributes($data),
                'team_id' => $team->id,
                'social_set_id' => $setId,
                'status' => filled($data['scheduled_at'] ?? null) ? 'scheduled' : 'draft',
            ]);

            self::placeInList($team, $post, $data);

            return $post;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function update(Post $post, array $data): Post
    {
        return DB::transaction(function () use ($post, $data) {
            $post->fill(self::attributes($data));
            $post->status = $post->scheduled_at ? 'scheduled' : 'draft';
            $post->save();

            self::placeInList($post->team, $post, $data);

            return $post;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        return array_diff_key($data, ['list_id' => 1, 'position' => 1]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function placeInList(Team $team, Post $post, array $data): void
    {
        if (isset($data['list_id'])) {
            /** @var PostList $list */
            $list = $team->postLists()->findOrFail($data['list_id']);
            $list->addPost($post, isset($data['position']) ? (int) $data['position'] : null);
        }
    }
}
