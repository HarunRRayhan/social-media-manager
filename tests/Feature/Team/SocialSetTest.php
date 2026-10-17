<?php

use App\Models\Post;
use App\Models\SocialSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
    $this->default = $this->team->socialSets()->sole();

    // Deleting a set needs a fresh passkey/email confirmation; tests below start confirmed.
    $this->withSession(['auth.password_confirmed_at' => time()]);
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
    $this->assertSoftDeleted($set);
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

test('deleting a set needs a fresh confirmation', function () {
    $set = $this->team->socialSets()->create(['name' => 'Clients']);

    $this->flushSession()
        ->actingAs($this->owner)
        ->delete(route('social-sets.destroy', $set))
        ->assertRedirect(route('password.confirm'));

    $this->assertNotSoftDeleted($set);
});

test('a deleted set hides its posts until restored', function () {
    $set = $this->team->socialSets()->create(['name' => 'Clients']);
    $post = Post::factory()->for($set)->create(['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
    $token = $this->team->createToken('team')->plainTextToken;

    $this->actingAs($this->owner)->delete(route('social-sets.destroy', $set));

    $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/posts/{$post->id}")->assertNotFound();
    $this->artisan('posts:publish-due')->expectsOutput('Queued 0 post(s).');
    $this->actingAs($this->owner)->get(route('social-sets.index'))
        ->assertInertia(fn ($page) => $page->where('deletedSocialSets.0.name', 'Clients')->where('deletedSocialSets.0.posts_count', 1));

    $this->actingAs($this->owner)->post(route('social-sets.restore', $set))->assertRedirect(route('social-sets.index'));

    $this->assertNotSoftDeleted($set);
    auth()->forgetGuards(); // the API call below must authenticate by token, not the web session
    $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/posts/{$post->id}")->assertOk();
});

test('members cannot restore and other teams 404', function () {
    $set = $this->team->socialSets()->create(['name' => 'Clients']);
    $set->delete();

    $this->actingAs(memberOf($this->team, 'member'))->post(route('social-sets.restore', $set))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('social-sets.restore', $set))->assertNotFound();
});

test('sets deleted over 30 days ago are purged with their posts', function () {
    $old = $this->team->socialSets()->create(['name' => 'Old']);
    $recent = $this->team->socialSets()->create(['name' => 'Recent']);
    $oldPost = Post::factory()->for($old)->create();
    $old->delete();
    $recent->delete();
    SocialSet::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);

    $this->artisan('social-sets:purge')->expectsOutput('Purged 1 social set(s).');

    $this->assertModelMissing($old);
    $this->assertModelMissing($oldPost);
    $this->assertSoftDeleted($recent);
});
