<?php

namespace App\Mcp\Tools;

use App\Http\Resources\PostListResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show a post list with its posts in order. Each post\'s lists entry for this list carries its 1-based position (Part N).')]
class GetList extends ApiTool
{
    protected function run(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);
        $list = $this->loadVisibleListPosts($request, $this->visibleLists($request)->findOrFail((int) $data['id']));

        return $this->json(PostListResource::make($list));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }
}
