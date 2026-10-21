<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A post's place in a list.
 *
 * @property int $post_list_id
 * @property int $post_id
 * @property int $position 1-based
 */
class PostListItem extends Pivot
{
    public $incrementing = true;
}
