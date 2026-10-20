<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\TokenScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SocialAccountController extends Controller
{
    use TokenScope;

    /**
     * List the connected accounts the token can reach, with the social sets each one posts for.
     *
     * A social-set token sees only the accounts in its set, and only that set's id.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $set = $this->tokenSocialSet($request);
        $sets = $this->visibleSocialSets($request)->pluck('id');

        $accounts = $this->team($request)->socialAccounts()
            ->when($set, fn ($query) => $query->whereHas('socialSets', fn ($query) => $query->whereKey($set->id)))
            ->with(['socialSets' => fn ($query) => $query->whereIn('social_sets.id', $sets)])
            ->orderBy('id')
            ->get();

        return SocialAccountResource::collection($accounts);
    }

    /**
     * Replace the social sets a connected account posts for. Needs a whole-team token.
     *
     * Every id must be one of the team's sets that is not deleted. Links to deleted sets are kept, so restoring a set brings the account back.
     */
    public function updateSocialSets(Request $request, int $account): SocialAccountResource
    {
        $team = $this->team($request);
        abort_unless($team->tokenCan('*'), 403, 'Only a whole-team token can change social set membership.');

        $model = $team->socialAccounts()->findOrFail($account);
        $ids = $request->validate([
            'social_set_ids' => ['present', 'array'],
            'social_set_ids.*' => ['integer', Rule::exists('social_sets', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
        ])['social_set_ids'];

        $model->syncSocialSets($ids);

        return SocialAccountResource::make($model->load('socialSets'));
    }
}
