<?php

use App\Mcp\Servers\SmmServer;
use App\Mcp\Tools\AddPostToList;
use App\Mcp\Tools\CreateList;
use App\Mcp\Tools\CreatePost;
use App\Mcp\Tools\GetList;
use App\Mcp\Tools\ListPosts;
use App\Models\Post;
use App\Models\PostList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam();
    $this->set = $this->team->socialSets()->sole();
    $this->token = $this->team->createToken('mcp');
});

/** The team authenticated through Sanctum with a real token, so set-limited abilities resolve as in production. */
function mcpAs($team, $token)
{
    return SmmServer::actingAs($team->withAccessToken($token->accessToken), 'sanctum');
}

function rpc(string $method, array $params = [], int $id = 1): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
}

test('the mcp endpoint needs a bearer api token', function () {
    $this->postJson('/mcp', rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]))
        ->assertUnauthorized();
});

test('initialize and tools/list work over http with a bearer token', function () {
    $headers = ['Authorization' => 'Bearer '.$this->token->plainTextToken];

    $this->postJson('/mcp', rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]), $headers)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Social Media Manager');

    $names = collect($this->postJson('/mcp', rpc('tools/list'), $headers)->assertOk()->json('result.tools'))->pluck('name');

    expect($names->all())->toEqualCanonicalizing([
        'list-social-sets', 'list-social-accounts', 'list-posts', 'get-post', 'create-post', 'update-post', 'delete-post',
        'list-lists', 'create-list', 'get-list', 'add-post-to-list', 'remove-post-from-list', 'reorder-list',
    ]);
});

test('create_list and add_post_to_list behave like the api', function () {
    $post = Post::factory()->for($this->set)->create();

    $created = mcpAs($this->team, $this->token)->tool(CreateList::class, ['name' => 'Series A']);
    $created->assertOk()->assertSee('Series A');
    $list = PostList::sole();
    expect($list->team_id)->toBe($this->team->id);

    mcpAs($this->team, $this->token)->tool(AddPostToList::class, ['list_id' => $list->id, 'post_id' => $post->id])
        ->assertOk()->assertSee('"position":1');

    mcpAs($this->team, $this->token)->tool(GetList::class, ['id' => 999])->assertHasErrors(['Not found.']);
    mcpAs($this->team, $this->token)->tool(CreateList::class, [])->assertHasErrors();
});

test('create_post validates with the api rules and a set token stays in its set', function () {
    $other = $this->team->socialSets()->create(['name' => 'Clients']);
    $setToken = $this->team->createToken('set', [$this->set->tokenAbility()]);

    mcpAs($this->team, $this->token)->tool(CreatePost::class, ['platform' => 'x'])->assertHasErrors();

    mcpAs($this->team, $setToken)->tool(CreatePost::class, ['platform' => 'x', 'body' => 'hi'])
        ->assertOk()->assertSee('"social_set_id":'.$this->set->id);

    mcpAs($this->team, $setToken)->tool(CreatePost::class, ['platform' => 'x', 'social_set_id' => $other->id])
        ->assertHasErrors(['This token can only post to its own social set.']);

    Post::factory()->for($other)->create();
    mcpAs($this->team, $setToken)->tool(ListPosts::class, [])->assertOk()->assertDontSee('"social_set_id":'.$other->id);
});
