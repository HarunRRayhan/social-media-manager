<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.threads' => ['app_id' => 'th-app', 'app_secret' => 'th-secret']]);

    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
});

function fakeThreadsLogin(): void
{
    Http::fake([
        'https://graph.threads.com/oauth/access_token' => ['access_token' => 'short', 'user_id' => '777'],
        'https://graph.threads.com/access_token*' => ['access_token' => 'long', 'token_type' => 'bearer', 'expires_in' => 5184000],
        'https://graph.threads.com/v1.0/me*' => ['id' => '777', 'username' => 'harun', 'name' => 'Harun', 'threads_profile_picture_url' => 'https://img/t.png'],
    ]);
}

test('the page says when threads is not configured, and the redirect is off', function () {
    config(['services.threads.app_id' => null]);

    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('threadsConfigured', false));
    $this->actingAs($this->owner)->get(route('connect.threads.redirect'))->assertNotFound();
});

test('members cannot start a threads connection', function () {
    $this->actingAs(memberOf($this->team, 'member'))->get(route('connect.threads.redirect'))->assertForbidden();
});

test('the redirect sends the user to threads with a state kept in the session', function () {
    $response = $this->actingAs($this->owner)->get(route('connect.threads.redirect'));

    $response->assertRedirectContains('https://threads.com/oauth/authorize?')
        ->assertRedirectContains('state='.session('threads_oauth.state'))
        ->assertRedirectContains('threads_content_publish')
        ->assertRedirectContains(urlencode(url('connect/threads/callback')));
});

test('a callback with a wrong state is refused without calling threads', function () {
    $this->actingAs($this->owner)
        ->withSession(['threads_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.threads.callback', ['state' => 'wrong', 'code' => 'abc']))
        ->assertRedirect(route('connections.index'));

    Http::assertNothingSent();
    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('the callback stores the profile with a long-lived encrypted token', function () {
    fakeThreadsLogin();

    $this->actingAs($this->owner)
        ->withSession(['threads_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.threads.callback', ['state' => 'right', 'code' => 'abc']))
        ->assertRedirect(route('connections.index'))
        ->assertInertiaFlash('toast.message', 'Connected @harun on Threads.');

    $account = $this->team->socialAccounts()->sole();
    expect($account)->provider->toBe('threads')->external_id->toBe('777')->username->toBe('harun')
        ->avatar_url->toBe('https://img/t.png')->access_token->toBe('long')
        ->and($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)))->toBeTrue()
        ->and($account->getRawOriginal('access_token'))->not->toBe('long');

    Http::assertSent(fn ($r) => $r->url() === 'https://graph.threads.com/oauth/access_token' && $r['code'] === 'abc' && $r['client_secret'] === 'th-secret' && $r['redirect_uri'] === url('connect/threads/callback'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'grant_type=th_exchange_token') && str_contains($r->url(), 'access_token=short'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'v1.0/me') && $r->hasHeader('Authorization', 'Bearer long'));
});

test('signing in again updates the same account', function () {
    $existing = SocialAccount::factory()->for($this->team)->create(['provider' => 'threads', 'external_id' => '777', 'access_token' => 'old']);
    fakeThreadsLogin();

    $this->actingAs($this->owner)
        ->withSession(['threads_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.threads.callback', ['state' => 'right', 'code' => 'abc']));

    expect($this->team->socialAccounts()->sole())->id->toBe($existing->id)->access_token->toBe('long');
});

test('a failed exchange shows the error and stores nothing', function () {
    Http::fake(['https://graph.threads.com/oauth/access_token' => Http::response(['error_message' => 'Invalid code.'], 400)]);

    $this->actingAs($this->owner)
        ->withSession(['threads_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.threads.callback', ['state' => 'right', 'code' => 'abc']))
        ->assertInertiaFlash('toast.message', 'Could not connect to Threads: Threads: Invalid code.');

    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('tokens in the second half of their life are refreshed, others left alone', function () {
    Http::fake(['https://graph.threads.com/refresh_access_token*' => ['access_token' => 'fresh', 'expires_in' => 5184000]]);
    $due = SocialAccount::factory()->for($this->team)->create(['provider' => 'threads', 'access_token' => 'aging', 'token_expires_at' => now()->addDays(20)]);
    $young = SocialAccount::factory()->for($this->team)->create(['provider' => 'threads', 'access_token' => 'young', 'token_expires_at' => now()->addDays(50)]);
    $expired = SocialAccount::factory()->for($this->team)->create(['provider' => 'threads', 'access_token' => 'dead', 'token_expires_at' => now()->subDay()]);

    $this->artisan('threads:refresh-tokens')->assertSuccessful();

    expect($due->fresh()->access_token)->toBe('fresh')
        ->and($due->fresh()->token_expires_at->isAfter(now()->addDays(59)))->toBeTrue()
        ->and($young->fresh()->access_token)->toBe('young')
        ->and($expired->fresh()->access_token)->toBe('dead');
    Http::assertSentCount(1);
});
