<?php

namespace App\Mcp\Tools;

use App\Http\Resources\SocialAccountResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the connected social accounts the token can reach, each with the ids of the social sets it posts for.')]
class ListSocialAccounts extends ApiTool
{
    protected function run(Request $request): Response
    {
        $set = $this->tokenSocialSet($request);
        $sets = $this->visibleSocialSets($request)->pluck('id');

        $accounts = $this->team($request)->socialAccounts()
            ->when($set, fn ($query) => $query->whereHas('socialSets', fn ($query) => $query->whereKey($set->id)))
            ->with(['socialSets' => fn ($query) => $query->whereIn('social_sets.id', $sets)])
            ->orderBy('id')
            ->get();

        return $this->json(SocialAccountResource::collection($accounts));
    }
}
