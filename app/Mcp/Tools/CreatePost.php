<?php

namespace App\Mcp\Tools;

use App\Actions\SavePost;
use App\Enums\Platform;
use App\Http\Requests\Api\V1\StorePostRequest;
use App\Http\Resources\PostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a post. Without scheduled_at it is a draft; with a future scheduled_at it is scheduled. A whole-team token must give social_set_id. Optional list_id (+ 1-based position, default appended) puts it into a list.')]
class CreatePost extends ApiTool
{
    protected function run(Request $request): Response
    {
        $data = $this->validateWith($request, StorePostRequest::class);
        $post = SavePost::create($this->team($request), $this->tokenSocialSet($request), $data);

        return $this->json(PostResource::make($post));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'social_set_id' => $schema->integer(),
            'platform' => $schema->string()->enum(array_column(Platform::cases(), 'value'))->required(),
            'body' => $schema->string(),
            'media' => $schema->array()->items($schema->string())->description('Public http(s) URLs of images or a video.'),
            'scheduled_at' => $schema->string()->description('ISO 8601, in the future.'),
            'list_id' => $schema->integer(),
            'position' => $schema->integer()->min(1),
        ];
    }
}
