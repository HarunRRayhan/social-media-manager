<?php

namespace App\Mcp\Tools;

use App\Actions\DeletePost as DeletePostAction;
use App\Http\Resources\PostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a post. A draft or scheduled post is removed. A published post is taken down from every account it went out to and kept with status deleted; if an account cannot delete it, the error says what to do.')]
class DeletePost extends ApiTool
{
    protected function run(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);
        $takenDown = DeletePostAction::run($this->visiblePosts($request)->findOrFail((int) $data['id']));

        return $takenDown ? $this->json(PostResource::make($takenDown)) : Response::text('Post deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }
}
