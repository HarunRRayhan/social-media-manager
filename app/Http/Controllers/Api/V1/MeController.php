<?php

namespace App\Http\Controllers\Api\V1;

use App\Concerns\TokenScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialSetResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use TokenScope;

    /**
     * Show the team that owns the access token, and whether the token reaches the full team or one social set.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $team = $this->team($request);
        $set = $this->tokenSocialSet($request);

        return response()->json([
            'data' => [
                'team' => ['id' => $team->id, 'name' => $team->name],
                'scope' => $set
                    ? ['type' => 'social_set', 'social_set' => SocialSetResource::make($set)]
                    : ['type' => 'team'],
            ],
        ]);
    }
}
