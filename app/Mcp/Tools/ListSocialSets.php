<?php

namespace App\Mcp\Tools;

use App\Http\Resources\SocialSetResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the social sets the token can reach: every set of the team, or only the token\'s own set.')]
class ListSocialSets extends ApiTool
{
    protected function run(Request $request): Response
    {
        return $this->json(SocialSetResource::collection($this->visibleSocialSets($request)->orderBy('id')->get()));
    }
}
