<?php

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $social_set_id
 * @property string $platform
 * @property string|null $body
 * @property array<int, string>|null $media
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $published_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['team_id', 'social_set_id', 'platform', 'body', 'media', 'status', 'scheduled_at', 'published_at', 'error'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
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

    /** @return HasMany<PostTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /** Only drafts and scheduled posts can still change; queued, published, and failed ones are locked. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
