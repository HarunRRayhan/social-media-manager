<?php

use App\Models\Post;
use App\Models\PostList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
    $this->set = $this->team->socialSets()->sole();
});

test('the lists page shows only the current teams lists', function () {
    $mine = PostList::factory()->for($this->team)->create();
    PostList::factory()->create();

    $this->actingAs($this->owner)
        ->get(route('lists.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('team/lists')->has('lists', 1)->where('lists.0.id', $mine->id));
});

test('an owner creates, reorders, removes from, renames and deletes a list', function () {
    [$a, $b] = Post::factory()->count(2)->for($this->set)->create();

    $this->actingAs($this->owner)->post(route('lists.store'), ['name' => 'Series'])->assertRedirect();
    $list = $this->team->postLists()->sole();

    $this->actingAs($this->owner)->put(route('posts.list.update', $a), ['list_id' => $list->id])->assertRedirect();
    $this->actingAs($this->owner)->put(route('posts.list.update', $b), ['list_id' => $list->id])->assertRedirect();
    expect($list->posts()->pluck('posts.id')->all())->toBe([$a->id, $b->id]);

    $this->actingAs($this->owner)->put(route('lists.reorder', $list), ['post_ids' => [$b->id, $a->id]])->assertRedirect();
    expect($list->posts()->pluck('posts.id')->all())->toBe([$b->id, $a->id]);

    $this->actingAs($this->owner)
        ->get(route('lists.show', $list))
        ->assertInertia(fn ($page) => $page->component('team/list')->where('list.posts.0.id', $b->id)->where('list.posts.0.position', 1));

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => now()->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->has('lists', 1)->where('posts.0.lists.0.position', fn ($p) => in_array($p, [1, 2])));

    $this->actingAs($this->owner)->delete(route('lists.posts.destroy', [$list, $b]))->assertRedirect();
    expect($list->posts()->pluck('posts.id')->all())->toBe([$a->id]);

    $this->actingAs($this->owner)->put(route('posts.list.update', $a), ['list_id' => null])->assertRedirect();
    expect($list->posts()->count())->toBe(0);

    $this->actingAs($this->owner)->patch(route('lists.update', $list), ['name' => 'Renamed'])->assertRedirect();
    $this->actingAs($this->owner)->delete(route('lists.destroy', $list))->assertRedirect(route('lists.index'));
    $this->assertSoftDeleted('post_lists', ['id' => $list->id, 'name' => 'Renamed']);
});

test('a member cannot manage lists and another team cannot see them', function () {
    $list = PostList::factory()->for($this->team)->create();

    $this->actingAs(memberOf($this->team, 'member'))->post(route('lists.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs(memberOf($this->team, 'member'))->delete(route('lists.destroy', $list))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('lists.show', $list))->assertNotFound();
});
