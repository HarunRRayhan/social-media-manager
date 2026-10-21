<?php

namespace App\Mcp\Tools;

use App\Http\Resources\PostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show one post with its targets and the lists it is part of.')]
class GetPost extends ApiTool
{
    protected function run(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        return $this->json(PostResource::make($this->visiblePosts($request)->with('targets.socialAccount', 'lists')->findOrFail((int) $data['id'])));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }
}
