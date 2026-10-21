<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Remove a post from a list; the posts after it move up. The post itself is kept.')]
class RemovePostFromList extends ApiTool
{
    protected function run(Request $request): Response
    {
        $data = $request->validate(['list_id' => ['required', 'integer'], 'post_id' => ['required', 'integer']]);

        $list = $this->visibleLists($request)->findOrFail((int) $data['list_id']);
        $list->removePost($this->visiblePosts($request)->findOrFail((int) $data['post_id']));

        return (new GetList)->handle(new Request(['id' => $list->id]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'post_id' => $schema->integer()->required(),
        ];
    }
}
