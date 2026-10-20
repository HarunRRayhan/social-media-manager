<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
    $this->default = $this->team->socialSets()->sole();
});

test('guests are redirected to login', function () {
    $this->get(route('calendar.index'))->assertRedirect(route('login'));
});

test('only the current teams posts are shown', function () {
    $mine = Post::factory()->for($this->default)->create(['status' => 'scheduled', 'platform' => 'x', 'scheduled_at' => '2026-10-10 09:00:00']);
    $foreignSet = User::factory()->create()->currentTeam()->socialSets()->sole();
    Post::factory()->for($foreignSet)->create(['status' => 'scheduled', 'scheduled_at' => '2026-10-10 09:00:00']);

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('team/calendar')
            ->where('month', '2026-10')
            ->has('posts', 1)
            ->where('posts.0.id', $mine->id)
            ->where('posts.0.platform', 'x')
            ->where('posts.0.status', 'scheduled')
            ->where('posts.0.social_set', $this->default->name));
});

test('the set filter limits posts to that set', function () {
    $other = $this->team->socialSets()->create(['name' => 'Clients']);
    Post::factory()->for($this->default)->create(['scheduled_at' => '2026-10-10 09:00:00']);
    $inOther = Post::factory()->for($other)->create(['scheduled_at' => '2026-10-11 09:00:00']);

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page->has('posts', 2)->where('set', null));

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => '2026-10', 'set' => $other->id]))
        ->assertInertia(fn ($page) => $page->has('posts', 1)->where('posts.0.id', $inOther->id)->where('set', $other->id));
});

test('a foreign or deleted set id is rejected', function () {
    $foreign = User::factory()->create()->currentTeam()->socialSets()->sole();
    $deleted = $this->team->socialSets()->create(['name' => 'Old']);
    $deleted->delete();

    foreach ([$foreign->id, $deleted->id, 'abc'] as $set) {
        $this->actingAs($this->owner)
            ->get(route('calendar.index', ['set' => $set]))
            ->assertSessionHasErrors('set');
    }

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => '2026-13']))
        ->assertSessionHasErrors('month');
});

test('the month includes a day of padding each side and nothing further', function () {
    $at = fn (string $time) => Post::factory()->for($this->default)->create(['scheduled_at' => $time])->id;

    $at('2026-09-29 23:59:59');
    $padBefore = $at('2026-09-30 00:00:00');
    $first = $at('2026-10-01 00:00:00');
    $last = $at('2026-10-31 23:59:59');
    $padAfter = $at('2026-11-01 23:59:59');
    $at('2026-11-02 00:00:00');

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page->where('posts', fn ($posts) => collect($posts)->pluck('id')->all() === [$padBefore, $first, $last, $padAfter]));
});

test('the day falls back from scheduled to published to created', function () {
    $published = Post::factory()->for($this->default)->create(['status' => 'published', 'published_at' => '2026-10-05 10:00:00']);
    $draft = Post::factory()->for($this->default)->create(['created_at' => '2026-10-06 10:00:00']);
    // Scheduled in November, so it stays off October even though it was written then.
    Post::factory()->for($this->default)->create(['status' => 'scheduled', 'scheduled_at' => '2026-11-20 10:00:00', 'created_at' => '2026-10-07 10:00:00']);

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page->has('posts', 2)
            ->where('posts.0.id', $published->id)
            ->where('posts.0.at', '2026-10-05T10:00:00+00:00')
            ->where('posts.1.id', $draft->id));
});

test('posts in a deleted set are hidden', function () {
    $other = $this->team->socialSets()->create(['name' => 'Gone']);
    Post::factory()->for($other)->create(['scheduled_at' => '2026-10-10 09:00:00']);
    $other->delete();

    $this->actingAs($this->owner)
        ->get(route('calendar.index', ['month' => '2026-10']))
        ->assertInertia(fn ($page) => $page->has('posts', 0));
});

test('the month defaults to the current one', function () {
    $this->travelTo('2026-03-15 12:00:00');

    $this->actingAs($this->owner)
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page->where('month', '2026-03'));
});
