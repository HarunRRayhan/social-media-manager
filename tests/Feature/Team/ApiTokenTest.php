<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withSession(['auth.password_confirmed_at' => time()]));

test('api token page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('api-tokens.edit'))
        ->assertOk();
});

test('a whole-team token is created and its plaintext is shown once', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam();

    $this->actingAs($user)
        ->followingRedirects()
        ->post(route('api-tokens.store'), ['name' => 'Content Machine'])
        ->assertInertia(fn ($page) => $page->has('newApiToken')->has('tokens', 1)->where('tokens.0.scope', 'Whole team'));

    $token = $team->tokens()->sole();
    expect($token->name)->toBe('Content Machine')
        ->and($token->abilities)->toBe(['*']);

    $this->actingAs($user)
        ->get(route('api-tokens.edit'))
        ->assertInertia(fn ($page) => $page->where('newApiToken', null)->has('tokens', 1));
});

test('a token can be limited to one social set', function () {
    $user = User::factory()->create();
    $set = $user->currentTeam()->socialSets()->create(['name' => 'Clients']);

    $this->actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'CM', 'social_set_id' => $set->id])
        ->assertRedirect(route('api-tokens.edit'));

    expect($user->currentTeam()->tokens()->sole()->abilities)->toBe(["social-set:{$set->id}"]);

    $this->actingAs($user)
        ->get(route('api-tokens.edit'))
        ->assertInertia(fn ($page) => $page->where('tokens.0.scope', 'Clients'));
});

test('a token cannot be limited to another teams set', function () {
    $user = User::factory()->create();
    $foreign = User::factory()->create()->currentTeam()->socialSets()->sole();

    $this->actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'CM', 'social_set_id' => $foreign->id])
        ->assertSessionHasErrors('social_set_id');

    expect($user->currentTeam()->tokens()->count())->toBe(0);
});

test('a created token authenticates the api as the team', function () {
    $team = User::factory()->create()->currentTeam();
    $plain = $team->createToken('CM')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.team.id', $team->id);
});

test('a token can be revoked and then no longer authenticates', function () {
    $user = User::factory()->create();
    $plain = $user->currentTeam()->createToken('CM')->plainTextToken;
    $token = $user->currentTeam()->tokens()->sole();

    $this->actingAs($user)
        ->delete(route('api-tokens.destroy', $token))
        ->assertRedirect(route('api-tokens.edit'));

    $this->assertModelMissing($token);

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('another teams token cannot be revoked', function () {
    $other = User::factory()->create()->currentTeam();
    $other->createToken('CM');
    $token = $other->tokens()->sole();

    $this->actingAs(User::factory()->create())
        ->delete(route('api-tokens.destroy', $token))
        ->assertNotFound();

    $this->assertModelExists($token);
});

test('members cannot manage api tokens but admins can', function () {
    $team = User::factory()->create()->currentTeam();
    $team->createToken('CM');
    $token = $team->tokens()->sole();

    $member = memberOf($team, 'member');
    $this->actingAs($member)->get(route('api-tokens.edit'))->assertForbidden();
    $this->actingAs($member)->post(route('api-tokens.store'), ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($member)->delete(route('api-tokens.destroy', $token))->assertForbidden();

    $admin = memberOf($team, 'admin');
    $this->actingAs($admin)->get(route('api-tokens.edit'))->assertOk();
    $this->actingAs($admin)->post(route('api-tokens.store'), ['name' => 'Ops'])->assertRedirect(route('api-tokens.edit'));

    expect($team->tokens()->count())->toBe(2);
});

test('guests cannot open the api token page', function () {
    $this->get(route('api-tokens.edit'))->assertRedirect(route('login'));
});

test('the api token page requires a recent password confirmation', function () {
    $this->flushSession()
        ->actingAs(User::factory()->create())
        ->get(route('api-tokens.edit'))
        ->assertRedirect(route('password.confirm'));
});
