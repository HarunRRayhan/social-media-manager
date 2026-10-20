<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\TokenScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialSetResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SocialSetController extends Controller
{
    use TokenScope;

    /**
     * List the social sets the token can reach: every set of the team, or only the token's own set.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return SocialSetResource::collection($this->visibleSocialSets($request)->orderBy('id')->get());
    }

    /**
     * Create a social set in the token's team. Needs a whole-team token.
     */
    public function store(Request $request): JsonResponse
    {
        $team = $this->team($request);
        abort_unless($team->tokenCan('*'), 403, 'Only a whole-team token can create social sets.');

        $set = $team->socialSets()->create($request->validate(['name' => ['required', 'string', 'max:255']]));

        return SocialSetResource::make($set)->response()->setStatusCode(201);
    }
}
