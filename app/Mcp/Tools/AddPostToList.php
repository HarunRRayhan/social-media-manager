<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\V1\AddListPostRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Add a post to a list at a 1-based position (appended by default). A post already in the list is moved there. Returns the list in order.')]
class AddPostToList extends ApiTool
{
    protected function run(Request $request): Response
    {
        $listId = (int) $request->validate(['list_id' => ['required', 'integer']])['list_id'];
        $data = $this->validateWith($request, AddListPostRequest::class);

        $list = $this->visibleLists($request)->findOrFail($listId);
        $list->addPost($this->visiblePosts($request)->findOrFail((int) $data['post_id']), isset($data['position']) ? (int) $data['position'] : null);

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
            'position' => $schema->integer()->min(1),
        ];
    }
}
