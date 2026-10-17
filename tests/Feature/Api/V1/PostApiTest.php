<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
});

function apiAs(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

test('api requests without a token get 401', function () {
    $this->getJson('/api/v1/posts')->assertUnauthorized();
    $this->postJson('/api/v1/posts', ['platform' => 'bluesky'])->assertUnauthorized();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

test('me returns the token owner', function () {
    $this->getJson('/api/v1/me', apiAs($this->token))
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
            ],
        ]);
});

test('a post without scheduled_at is saved as a draft', function () {
    $response = $this->postJson('/api/v1/posts', [
        'platform' => 'bluesky',
        'body' => 'Hello',
        'media' => ['https://example.com/a.png'],
    ], apiAs($this->token));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.platform', 'bluesky')
        ->assertJsonPath('data.media.0', 'https://example.com/a.png');

    $this->assertDatabaseHas('posts', ['id' => $response->json('data.id'), 'user_id' => $this->user->id, 'status' => 'draft']);
});

test('a post with a future scheduled_at is scheduled', function () {
    $this->postJson('/api/v1/posts', [
        'platform' => 'x',
        'body' => 'Later',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ], apiAs($this->token))
        ->assertCreated()
        ->assertJsonPath('data.status', 'scheduled');
});

test('posts can be listed, filtered by status, and shown', function () {
    $headers = apiAs($this->token);
    $draft = Post::factory()->for($this->user)->create(['status' => 'draft']);
    Post::factory()->for($this->user)->create(['status' => 'published']);

    $this->getJson('/api/v1/posts', $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/posts?status=draft', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $draft->id);

    $this->getJson("/api/v1/posts/{$draft->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id);
});

test('a draft can be edited and its status follows scheduled_at', function () {
    $headers = apiAs($this->token);
    $post = Post::factory()->for($this->user)->create(['status' => 'draft']);

    $this->patchJson("/api/v1/posts/{$post->id}", [
        'body' => 'Edited',
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.body', 'Edited')
        ->assertJsonPath('data.status', 'scheduled');

    $this->patchJson("/api/v1/posts/{$post->id}", ['scheduled_at' => null], $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

test('a draft or scheduled post can be deleted', function () {
    $headers = apiAs($this->token);
    $post = Post::factory()->for($this->user)->create(['status' => 'scheduled']);

    $this->deleteJson("/api/v1/posts/{$post->id}", [], $headers)->assertNoContent();

    $this->assertModelMissing($post);
});

test('editing or deleting a published post returns 409', function () {
    $headers = apiAs($this->token);
    $post = Post::factory()->for($this->user)->create(['status' => 'published']);

    $this->patchJson("/api/v1/posts/{$post->id}", ['body' => 'Too late'], $headers)
        ->assertConflict();

    $this->deleteJson("/api/v1/posts/{$post->id}", [], $headers)
        ->assertConflict();

    $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'published', 'body' => $post->body]);
});

test('other users posts return 404', function () {
    $headers = apiAs($this->token);
    $other = Post::factory()->for(User::factory()->create())->create(['status' => 'draft']);

    $this->getJson("/api/v1/posts/{$other->id}", $headers)->assertNotFound();
    $this->patchJson("/api/v1/posts/{$other->id}", ['body' => 'Mine now'], $headers)->assertNotFound();
    $this->deleteJson("/api/v1/posts/{$other->id}", [], $headers)->assertNotFound();

    $this->getJson('/api/v1/posts', $headers)->assertJsonCount(0, 'data');
});

test('invalid platform and past scheduled_at are rejected', function () {
    $headers = apiAs($this->token);

    $this->postJson('/api/v1/posts', ['platform' => 'myspace'], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platform']);

    $this->postJson('/api/v1/posts', [
        'platform' => 'facebook',
        'scheduled_at' => now()->subHour()->toIso8601String(),
    ], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
});
