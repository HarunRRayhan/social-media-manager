<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Team;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared rules for the optional list placement of a post (`list_id` + 1-based `position`).
 */
trait ListRules
{
    /**
     * @return array<string, mixed>
     */
    protected function listRules(Team $team): array
    {
        return [
            'list_id' => ['nullable', 'integer', self::visibleList($team)],
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** A live list of the team that the token can see: team-wide, or of the token's own set. */
    public static function visibleList(Team $team): Exists
    {
        $set = $team->tokenSocialSet();

        return Rule::exists('post_lists', 'id')->where('team_id', $team->id)->whereNull('deleted_at')
            ->when($set, fn ($rule) => $rule->where(fn ($q) => $q->whereNull('social_set_id')->orWhere('social_set_id', $set->id)));
    }
}
