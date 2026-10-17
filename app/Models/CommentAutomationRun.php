<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One comment an automation acted on.
 *
 * @property int $id
 * @property int $comment_automation_id
 * @property string $comment_id
 * @property string|null $commenter_id
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['comment_automation_id', 'comment_id', 'commenter_id', 'status', 'error'])]
class CommentAutomationRun extends Model
{
    /** @return BelongsTo<CommentAutomation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(CommentAutomation::class, 'comment_automation_id');
    }
}
