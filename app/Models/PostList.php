<?php

namespace App\Models;

use Database\Factories\PostListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An ordered group of posts, such as the parts of a series. The order is SMM's own; `remote_ids` is where a
 * platform-side mirror (only YouTube has a playlist API) would keep its id, keyed by platform. No publisher
 * writes it yet.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $social_set_id
 * @property string $name
 * @property string|null $description
 * @property array<string, string>|null $remote_ids
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read PostListItem|null $pivot
 */
#[Fillable(['team_id', 'social_set_id', 'name', 'description', 'remote_ids'])]
class PostList extends Model
{
    /** @use HasFactory<PostListFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['remote_ids' => 'array'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<SocialSet, $this> */
    public function socialSet(): BelongsTo
    {
        return $this->belongsTo(SocialSet::class);
    }

    /**
     * In list order.
     *
     * @return BelongsToMany<Post, $this, PostListItem>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_list_items')->using(PostListItem::class)->withPivot('position')->orderByPivot('position');
    }

    /**
     * Put a post at a 1-based position (appended when null or past the end), shifting the rest down.
     * A post already in the list is moved instead of duplicated.
     */
    public function addPost(Post $post, ?int $position = null): void
    {
        DB::transaction(function () use ($post, $position) {
            $this->removePost($post);
            $count = $this->posts()->count();
            $position = max(1, min($position ?? $count + 1, $count + 1));
            $this->posts()->newPivotStatement()->where('post_list_id', $this->id)->where('position', '>=', $position)->increment('position');
            $this->posts()->attach($post, ['position' => $position]);
        });
    }

    public function removePost(Post $post): void
    {
        DB::transaction(function () use ($post) {
            $position = $this->posts()->newPivotStatement()->where('post_list_id', $this->id)->where('post_id', $post->id)->value('position');

            if ($position === null) {
                return;
            }

            $this->posts()->detach($post);
            $this->posts()->newPivotStatement()->where('post_list_id', $this->id)->where('position', '>', $position)->decrement('position');
        });
    }

    /**
     * Set the whole order. Every post of the list must appear exactly once.
     *
     * @param  array<int, int>  $postIds
     */
    public function reorder(array $postIds): void
    {
        $current = $this->posts()->pluck('posts.id')->all();

        if (count($postIds) !== count($current) || array_diff($current, $postIds) !== []) {
            throw ValidationException::withMessages(['post_ids' => 'post_ids must contain every post of the list exactly once.']);
        }

        DB::transaction(fn () => collect($postIds)->values()->each(
            fn (int $id, int $index) => $this->posts()->updateExistingPivot($id, ['position' => $index + 1]),
        ));
    }
}
