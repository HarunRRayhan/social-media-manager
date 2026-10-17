<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('api token settings page is displayed', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('api-tokens.edit'))
        ->assertOk();
});

test('a named token is created and its plaintext is shown once', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->followingRedirects()
        ->post(route('api-tokens.store'), ['name' => 'Content Machine'])
        ->assertInertia(fn ($page) => $page->has('newApiToken')->has('tokens', 1));

    $token = $user->tokens()->sole();
    expect($token->name)->toBe('Content Machine');

    $this->actingAs($user)
        ->get(route('api-tokens.edit'))
        ->assertInertia(fn ($page) => $page->where('newApiToken', null)->has('tokens', 1));
});

test('a created token authenticates the api', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('CM')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

test('a token can be revoked and then no longer authenticates', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('CM')->plainTextToken;
    $token = $user->tokens()->sole();

    $this->actingAs($user)
        ->delete(route('api-tokens.destroy', $token))
        ->assertRedirect(route('api-tokens.edit'));

    $this->assertModelMissing($token);

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

test('another users token cannot be revoked', function () {
    $owner = User::factory()->create();
    $owner->createToken('CM');
    $token = $owner->tokens()->sole();

    $this->actingAs(User::factory()->create())
        ->delete(route('api-tokens.destroy', $token))
        ->assertNotFound();

    $this->assertModelExists($token);
});

test('guests cannot open the api token settings', function () {
    $this->get(route('api-tokens.edit'))->assertRedirect(route('login'));
});
