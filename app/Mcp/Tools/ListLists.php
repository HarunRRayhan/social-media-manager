<?php

namespace App\Mcp\Tools;

use App\Http\Resources\PostListResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the post lists (ordered groups of posts, e.g. a series) the token can reach, with their post counts.')]
class ListLists extends ApiTool
{
    protected function run(Request $request): Response
    {
        return $this->json(PostListResource::collection($this->visibleLists($request)->withCount('posts')->orderBy('id')->get()));
    }
}
