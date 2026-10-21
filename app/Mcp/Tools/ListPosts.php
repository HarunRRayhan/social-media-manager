<?php

namespace App\Mcp\Tools;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Http\Requests\Api\V1\IndexPostRequest;
use App\Http\Resources\PostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List posts, newest first (up to 100), optionally filtered by status, platform, social set, and the date range they go out in.')]
class ListPosts extends ApiTool
{
    protected function run(Request $request): Response
    {
        $data = $this->validateWith($request, IndexPostRequest::class);

        $posts = $this->visiblePosts($request)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['platform'] ?? null, fn ($query, $platform) => $query->where('platform', $platform))
            ->when($data['social_set_id'] ?? null, fn ($query, $set) => $query->where('social_set_id', $set))
            ->when($data['from'] ?? null, fn ($query, $from) => $query->whereRaw('COALESCE(scheduled_at, published_at, created_at) >= ?', [$from]))
            ->when($data['to'] ?? null, fn ($query, $to) => $query->whereRaw('COALESCE(scheduled_at, published_at, created_at) <= ?', [$to]))
            ->with('targets.socialAccount', 'lists')
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->json(PostResource::collection($posts));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_column(PostStatus::cases(), 'value')),
            'platform' => $schema->string()->enum(array_column(Platform::cases(), 'value')),
            'social_set_id' => $schema->integer(),
            'from' => $schema->string()->description('Earliest go-out date/time (ISO 8601).'),
            'to' => $schema->string()->description('Latest go-out date/time (ISO 8601).'),
        ];
    }
}
