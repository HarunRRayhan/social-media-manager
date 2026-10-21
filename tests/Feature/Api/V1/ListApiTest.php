<?php

use App\Models\Post;
use App\Models\PostList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam();
    $this->set = $this->team->socialSets()->sole();
    $this->otherSet = $this->team->socialSets()->create(['name' => 'Clients']);
    $this->token = $this->team->createToken('test')->plainTextToken;
    $this->setToken = $this->team->createToken('set', [$this->set->tokenAbility()])->plainTextToken;
    $this->headers = ['Authorization' => 'Bearer '.$this->token];
});

test('lists can be created, listed, shown, renamed and deleted', function () {
    $created = $this->postJson('/api/v1/lists', ['name' => 'Docker series', 'description' => 'Five parts'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Docker series')
        ->assertJsonPath('data.social_set_id', null)
        ->assertJsonPath('data.posts_count', 0);
    $id = $created->json('data.id');

    $this->getJson('/api/v1/lists', $this->headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson("/api/v1/lists/{$id}", $this->headers)->assertOk()->assertJsonPath('data.posts', []);

    $this->patchJson("/api/v1/lists/{$id}", ['name' => 'Renamed', 'social_set_id' => $this->otherSet->id], $this->headers)
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.social_set_id', $this->otherSet->id);

    $this->deleteJson("/api/v1/lists/{$id}", [], $this->headers)->assertNoContent();
    $this->getJson("/api/v1/lists/{$id}", $this->headers)->assertNotFound();
    $this->assertSoftDeleted('post_lists', ['id' => $id]);
});

test('a list name is required and the set must belong to the team', function () {
    $this->postJson('/api/v1/lists', [], $this->headers)->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $foreign = User::factory()->create()->currentTeam()->socialSets()->sole();
    $this->postJson('/api/v1/lists', ['name' => 'x', 'social_set_id' => $foreign->id], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['social_set_id']);
});

test('posts are added in order, inserted at a position, reordered and removed', function () {
    $list = PostList::factory()->for($this->team)->create();
    [$a, $b, $c] = Post::factory()->count(3)->for($this->set)->create();

    $this->postJson("/api/v1/lists/{$list->id}/posts", ['post_id' => $a->id], $this->headers)->assertOk();
    $this->postJson("/api/v1/lists/{$list->id}/posts", ['post_id' => $b->id], $this->headers)->assertOk();
    $this->postJson("/api/v1/lists/{$list->id}/posts", ['post_id' => $c->id, 'position' => 1], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.posts.*.id', [$c->id, $a->id, $b->id])
        ->assertJsonPath('data.posts.0.lists.0', ['id' => $list->id, 'name' => $list->name, 'position' => 1]);

    // Adding again moves instead of duplicating.
    $this->postJson("/api/v1/lists/{$list->id}/posts", ['post_id' => $c->id, 'position' => 99], $this->headers)
        ->assertOk()->assertJsonPath('data.posts.*.id', [$a->id, $b->id, $c->id]);

    $this->putJson("/api/v1/lists/{$list->id}/posts/order", ['post_ids' => [$b->id, $c->id, $a->id]], $this->headers)
        ->assertOk()->assertJsonPath('data.posts.*.id', [$b->id, $c->id, $a->id]);

    $this->putJson("/api/v1/lists/{$list->id}/posts/order", ['post_ids' => [$b->id]], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['post_ids']);

    $this->deleteJson("/api/v1/lists/{$list->id}/posts/{$c->id}", [], $this->headers)->assertNoContent();
    $this->getJson("/api/v1/lists/{$list->id}", $this->headers)
        ->assertJsonPath('data.posts.*.id', [$b->id, $a->id])
        ->assertJsonPath('data.posts.1.lists.0.position', 2);
});

test('a post can be created or updated into a list', function () {
    $list = PostList::factory()->for($this->team)->create();
    $first = Post::factory()->for($this->set)->create();
    $list->addPost($first);

    $created = $this->postJson('/api/v1/posts', ['social_set_id' => $this->set->id, 'platform' => 'youtube', 'list_id' => $list->id], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.lists.0.position', 2);

    $this->patchJson("/api/v1/posts/{$created->json('data.id')}", ['list_id' => $list->id, 'position' => 1], $this->headers)
        ->assertOk()->assertJsonPath('data.lists.0.position', 1);

    expect($list->posts()->pluck('posts.id')->all())->toBe([$created->json('data.id'), $first->id]);

    $this->postJson('/api/v1/posts', ['social_set_id' => $this->set->id, 'platform' => 'youtube', 'list_id' => 999], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['list_id']);
});

test('lists and their posts are isolated per team', function () {
    $foreignTeam = User::factory()->create()->currentTeam();
    $foreignList = PostList::factory()->for($foreignTeam)->create();
    $foreignPost = Post::factory()->for($foreignTeam->socialSets()->sole())->create();
    $mine = PostList::factory()->for($this->team)->create();

    $this->getJson('/api/v1/lists', $this->headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->getJson("/api/v1/lists/{$foreignList->id}", $this->headers)->assertNotFound();
    $this->patchJson("/api/v1/lists/{$foreignList->id}", ['name' => 'x'], $this->headers)->assertNotFound();
    $this->postJson("/api/v1/lists/{$mine->id}/posts", ['post_id' => $foreignPost->id], $this->headers)->assertNotFound();
    $this->postJson('/api/v1/posts', ['social_set_id' => $this->set->id, 'platform' => 'x', 'list_id' => $foreignList->id], $this->headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['list_id']);
});

test('a set token sees team-wide lists and its own set lists only, and creates in its own set', function () {
    $headers = ['Authorization' => 'Bearer '.$this->setToken];
    $teamWide = PostList::factory()->for($this->team)->create();
    $own = PostList::factory()->for($this->team)->create(['social_set_id' => $this->set->id]);
    $other = PostList::factory()->for($this->team)->create(['social_set_id' => $this->otherSet->id]);

    $this->getJson('/api/v1/lists', $headers)->assertOk()->assertJsonPath('data.*.id', [$teamWide->id, $own->id]);
    $this->getJson("/api/v1/lists/{$other->id}", $headers)->assertNotFound();

    $this->postJson('/api/v1/lists', ['name' => 'Mine'], $headers)->assertCreated()->assertJsonPath('data.social_set_id', $this->set->id);
    $this->postJson('/api/v1/lists', ['name' => 'Theirs', 'social_set_id' => $this->otherSet->id], $headers)->assertForbidden();

    $otherPost = Post::factory()->for($this->otherSet)->create();
    $this->postJson("/api/v1/lists/{$teamWide->id}/posts", ['post_id' => $otherPost->id], $headers)->assertNotFound();
    $this->postJson('/api/v1/posts', ['platform' => 'x', 'list_id' => $other->id], $headers)->assertUnprocessable()->assertJsonValidationErrors(['list_id']);
    $this->postJson('/api/v1/posts', ['platform' => 'x', 'list_id' => $teamWide->id], $headers)->assertCreated()->assertJsonPath('data.lists.0.position', 1);
});
