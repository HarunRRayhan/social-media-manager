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

test('sets are listed with their post counts', function () {
    Post::factory()->count(2)->for($this->default)->create();

    $this->actingAs($this->owner)
        ->get(route('social-sets.index'))
        ->assertInertia(fn ($page) => $page->component('team/social-sets')->where('socialSets.0.posts_count', 2));
});

test('managers can create, rename and delete sets, members cannot', function () {
    $member = memberOf($this->team, 'member');
    $this->actingAs($member)->post(route('social-sets.store'), ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($member)->patch(route('social-sets.update', $this->default), ['name' => 'Nope'])->assertForbidden();

    $admin = memberOf($this->team, 'admin');
    $this->actingAs($admin)->post(route('social-sets.store'), ['name' => 'Clients'])->assertRedirect(route('social-sets.index'));
    $set = $this->team->socialSets()->where('name', 'Clients')->sole();

    $this->actingAs($admin)->patch(route('social-sets.update', $set), ['name' => 'Agency'])->assertRedirect();
    expect($set->fresh()->name)->toBe('Agency');

    $this->actingAs($member)->delete(route('social-sets.destroy', $set))->assertForbidden();
    $this->actingAs($admin)->delete(route('social-sets.destroy', $set))->assertRedirect();
    $this->assertModelMissing($set);
});

test('the last set cannot be deleted', function () {
    $this->actingAs($this->owner)
        ->delete(route('social-sets.destroy', $this->default))
        ->assertSessionHasErrors('social_set');

    $this->assertModelExists($this->default);
});

test('another teams set 404s', function () {
    $foreign = User::factory()->create()->currentTeam()->socialSets()->sole();

    $this->actingAs($this->owner)->patch(route('social-sets.update', $foreign), ['name' => 'Mine'])->assertNotFound();
    $this->actingAs($this->owner)->delete(route('social-sets.destroy', $foreign))->assertNotFound();
});

test('deleting a set revokes the tokens limited to it and keeps the rest', function () {
    $set = $this->team->socialSets()->create(['name' => 'Clients']);
    $plain = $this->team->createToken('set', [$set->tokenAbility()])->plainTextToken;
    $this->team->createToken('default', [$this->default->tokenAbility()]);
    $this->team->createToken('team');

    $this->actingAs($this->owner)->delete(route('social-sets.destroy', $set))->assertRedirect();

    expect($this->team->tokens()->pluck('name')->sort()->values()->all())->toBe(['default', 'team']);

    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/me')->assertUnauthorized();
});
