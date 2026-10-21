<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\V1\ReorderListRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Replace a list\'s order. post_ids must name every post of the list exactly once, in the new order.')]
class ReorderList extends ApiTool
{
    protected function run(Request $request): Response
    {
        $listId = (int) $request->validate(['list_id' => ['required', 'integer']])['list_id'];
        $data = $this->validateWith($request, ReorderListRequest::class);

        $list = $this->visibleLists($request)->findOrFail($listId);
        $list->reorder(array_map('intval', $data['post_ids']));

        return (new GetList)->handle(new Request(['id' => $list->id]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->required(),
            'post_ids' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
