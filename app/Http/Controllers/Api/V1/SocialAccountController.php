<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\TokenScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
