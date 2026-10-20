<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
});

test('a handle and app password connect a bluesky account with the password encrypted', function () {
    Http::fake(['https://bsky.social/xrpc/com.atproto.server.createSession' => ['did' => 'did:plc:abc', 'handle' => 'harun.bsky.social', 'accessJwt' => 'jwt']]);

    $this->actingAs($this->owner)
        ->post(route('connect.bluesky.store'), ['handle' => '@harun.bsky.social', 'password' => 'app-pass-word'])
        ->assertRedirect(route('connections.index'));

    Http::assertSent(fn ($r) => $r['identifier'] === 'harun.bsky.social' && $r['password'] === 'app-pass-word');
    $account = $this->team->socialAccounts()->sole();
    expect($account)->provider->toBe('bluesky')->external_id->toBe('did:plc:abc')->name->toBe('harun.bsky.social')->access_token->toBe('app-pass-word')
        ->and($account->getRawOriginal('access_token'))->not->toContain('app-pass-word');
});

test('wrong bluesky credentials are a form error and store nothing', function () {
    Http::fake(['https://bsky.social/xrpc/*' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401)]);

    $this->actingAs($this->owner)
        ->post(route('connect.bluesky.store'), ['handle' => 'harun.bsky.social', 'password' => 'wrong'])
        ->assertSessionHasErrors('password');

    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('members cannot connect bluesky', function () {
    $this->actingAs(memberOf($this->team, 'member'))
        ->post(route('connect.bluesky.store'), ['handle' => 'harun.bsky.social', 'password' => 'x'])
        ->assertForbidden();

    Http::assertNothingSent();
});
