<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\V1\StorePostListRequest;
use App\Http\Resources\PostListResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a post list. Optionally tie it to one social set; a set token\'s list always belongs to its own set.')]
class CreateList extends ApiTool
{
    protected function run(Request $request): Response
    {
        $tokenSet = $this->tokenSocialSet($request);
        $data = $this->validateWith($request, StorePostListRequest::class);
        $data['social_set_id'] ??= $tokenSet?->id;

        if ($tokenSet && (int) $data['social_set_id'] !== $tokenSet->id) {
            throw new AuthorizationException('This token can only create lists in its own social set.');
        }

        return $this->json(PostListResource::make($this->team($request)->postLists()->create($data)->loadCount(['posts' => $this->team($request)->visiblePostsScope()])));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'description' => $schema->string(),
            'social_set_id' => $schema->integer(),
        ];
    }
}
