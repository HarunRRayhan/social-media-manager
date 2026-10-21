<?php

namespace App\Mcp\Tools;

use App\Actions\SavePost;
use App\Enums\Platform;
use App\Http\Requests\Api\V1\UpdatePostRequest;
use App\Http\Resources\PostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a draft or scheduled post. Only the given fields change; status follows scheduled_at. list_id (+ position) adds it to a list or moves it there.')]
class UpdatePost extends ApiTool
{
    protected function run(Request $request): Response
    {
        $id = (int) $request->validate(['id' => ['required', 'integer']])['id'];
        $post = $this->visiblePosts($request)->findOrFail($id);

        if (! $post->isEditable()) {
            return Response::error('Only draft or scheduled posts can be edited.');
        }

        $data = $this->validateWith($request, UpdatePostRequest::class);

        return $this->json(PostResource::make(SavePost::update($post, $data)));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'platform' => $schema->string()->enum(array_column(Platform::cases(), 'value')),
            'body' => $schema->string(),
            'media' => $schema->array()->items($schema->string()),
            'scheduled_at' => $schema->string()->description('ISO 8601, in the future; null makes it a draft.'),
            'list_id' => $schema->integer(),
            'position' => $schema->integer()->min(1),
        ];
    }
}
