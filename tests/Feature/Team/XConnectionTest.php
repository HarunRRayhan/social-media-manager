<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Publishers\X;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.x' => ['client_id' => 'x-app', 'client_secret' => 'x-secret']]);

    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
});

function fakeXLogin(array $token = []): void
{
    Http::fake([
        'https://api.x.com/2/oauth2/token' => ['token_type' => 'bearer', 'access_token' => 'x-token', 'refresh_token' => 'x-refresh', 'expires_in' => 7200, ...$token],
        'https://api.x.com/2/users/me*' => ['data' => ['id' => '123', 'username' => 'harun', 'name' => 'Harun R', 'profile_image_url' => 'https://pbs.twimg.com/p.jpg']],
    ]);
}

function xCallback(array $query = ['state' => 'right', 'code' => 'abc'], array $session = [])
{
    return test()->actingAs(test()->owner)
        ->withSession(['x_oauth' => ['state' => 'right', 'team_id' => test()->team->id], 'x_oauth_verifier' => 'verifier-1', ...$session])
        ->get(route('connect.x.callback', $query));
}

function xAccount(array $attributes = []): SocialAccount
{
    return SocialAccount::factory()->for(test()->team)->create([
        'provider' => 'x', 'external_id' => '123', 'username' => 'harun', 'access_token' => 'old',
        'token_expires_at' => now()->addMinutes(10), 'meta' => ['refresh_token' => Crypt::encryptString('r1')], ...$attributes,
    ]);
}

test('the page says when x is not configured, and the redirect is off', function () {
    config(['services.x.client_id' => null]);

    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('xConfigured', false));
    $this->actingAs($this->owner)->get(route('connect.x.redirect'))->assertNotFound();
});

test('members cannot start an x connection', function () {
    $this->actingAs(memberOf($this->team, 'member'))->get(route('connect.x.redirect'))->assertForbidden();
});

test('the redirect sends the user to x with a pkce challenge, the scopes, and a state kept in the session', function () {
    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('xConfigured', true));

    $response = $this->actingAs($this->owner)->get(route('connect.x.redirect'))
        ->assertRedirectContains('https://x.com/i/oauth2/authorize?')
        ->assertRedirectContains('state='.session('x_oauth.state'))
        ->assertRedirectContains('scope=tweet.read+tweet.write+users.read+media.write+offline.access')
        ->assertRedirectContains('code_challenge_method=S256')
        ->assertRedirectContains(urlencode(url('connect/x/callback')));

    $challenge = rtrim(strtr(base64_encode(hash('sha256', session('x_oauth_verifier'), true)), '+/', '-_'), '=');
    $response->assertRedirectContains('code_challenge='.$challenge);
});

test('a callback with a wrong state is refused without calling x', function () {
    xCallback(['state' => 'wrong', 'code' => 'abc'])->assertRedirect(route('connections.index'));

    Http::assertNothingSent();
    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('a cancelled sign-in stores nothing', function () {
    xCallback(['state' => 'right', 'error' => 'access_denied'])->assertInertiaFlash('toast.message', 'X connection was cancelled.');

    Http::assertNothingSent();
});

test('the callback exchanges the code with the verifier and basic auth, then stores the user with both tokens', function () {
    fakeXLogin();

    xCallback()->assertRedirect(route('connections.index'))->assertInertiaFlash('toast.message', 'Connected Harun R on X.');

    $account = $this->team->socialAccounts()->sole();
    expect($account)->provider->toBe('x')->external_id->toBe('123')->name->toBe('Harun R')->username->toBe('harun')
        ->avatar_url->toBe('https://pbs.twimg.com/p.jpg')->access_token->toBe('x-token')
        ->and(Crypt::decryptString($account->meta['refresh_token']))->toBe('x-refresh')
        ->and($account->token_expires_at->between(now()->addMinutes(119), now()->addMinutes(121)))->toBeTrue()
        ->and($account->getRawOriginal('access_token'))->not->toBe('x-token');

    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/oauth2/token' && $r['grant_type'] === 'authorization_code'
        && $r['code'] === 'abc' && $r['code_verifier'] === 'verifier-1' && $r['redirect_uri'] === url('connect/x/callback')
        && ! isset($r['client_secret']) && $r->hasHeader('Authorization', 'Basic '.base64_encode('x-app:x-secret')));
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.x.com/2/users/me?') && str_contains($r->url(), 'profile_image_url') && $r->hasHeader('Authorization', 'Bearer x-token'));
});

test('signing in again updates the same account and clears a reconnect flag', function () {
    $existing = xAccount(['meta' => ['reconnect' => true], 'access_token' => 'dead']);
    fakeXLogin();

    xCallback();

    $account = $this->team->socialAccounts()->sole();
    expect($account)->id->toBe($existing->id)->access_token->toBe('x-token')->meta->not->toHaveKey('reconnect');
});

test('a failed exchange shows the error and stores nothing', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_request', 'error_description' => 'Bad code.'], 400)]);

    xCallback()->assertInertiaFlash('toast.message', 'Could not connect to X: X: Bad code.');

    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('tokens due within 30 minutes are rotated: new access and refresh token stored, old refresh token spent once', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => ['access_token' => 'fresh', 'refresh_token' => 'r1-next', 'expires_in' => 7200]]);
    $due = xAccount();
    $young = xAccount(['external_id' => '456', 'access_token' => 'young', 'token_expires_at' => now()->addHours(1)]);
    $flagged = xAccount(['external_id' => '789', 'access_token' => 'dead', 'token_expires_at' => now()->subHour(), 'meta' => ['reconnect' => true]]);

    $this->artisan('x:refresh-tokens')->expectsOutputToContain('Refreshed 1 X token(s).')->assertSuccessful();

    expect($due->fresh())->access_token->toBe('fresh')
        ->and($due->fresh()->token_expires_at->gt(now()->addMinutes(110)))->toBeTrue()
        ->and(Crypt::decryptString($due->fresh()->meta['refresh_token']))->toBe('r1-next')
        ->and($young->fresh()->access_token)->toBe('young')
        ->and($flagged->fresh()->access_token)->toBe('dead');
    Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1' && $r->hasHeader('Authorization', 'Basic '.base64_encode('x-app:x-secret')));
    Http::assertSentCount(1);
});

test('a refused refresh flags the account for reconnecting and drops the dead refresh token', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_request', 'error_description' => 'Value passed for the token was invalid.'], 400)]);
    $due = xAccount();

    $this->artisan('x:refresh-tokens')->expectsOutputToContain('Refreshed 0 X token(s).')->assertSuccessful();

    expect($due->fresh())->meta->toBe(['reconnect' => true])->access_token->toBe('old');
    $this->actingAs($this->owner)->get(route('connections.index'))
        ->assertInertia(fn ($page) => $page->where('accounts.0.reconnect', true));
});

test('an x account with a short-lived but renewable token is not flagged to reconnect', function () {
    xAccount();

    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('accounts.0.reconnect', false));
});

test('a refused client secret (401) does not flag accounts for reconnecting', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401)]);
    $due = xAccount();

    $this->artisan('x:refresh-tokens')->expectsOutputToContain('Refreshed 0 X token(s).')->assertSuccessful();

    expect($due->fresh())->access_token->toBe('old')->and(Crypt::decryptString($due->fresh()->meta['refresh_token']))->toBe('r1');
});

test('a refresh response without a new refresh token keeps the current one', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => ['access_token' => 'fresh', 'expires_in' => 7200]]);
    $due = xAccount();

    $this->artisan('x:refresh-tokens')->expectsOutputToContain('Refreshed 1 X token(s).')->assertSuccessful();

    expect($due->fresh())->access_token->toBe('fresh')->and(Crypt::decryptString($due->fresh()->meta['refresh_token']))->toBe('r1');
});

test('a network failure during refresh leaves the account untouched', function () {
    Http::fake(['https://api.x.com/2/oauth2/token' => fn () => throw new ConnectionException('cURL error 28: https://api.x.com/2/oauth2/token')]);
    $due = xAccount();

    $this->artisan('x:refresh-tokens')->assertSuccessful();

    expect($due->fresh())->access_token->toBe('old')->and(Crypt::decryptString($due->fresh()->meta['refresh_token']))->toBe('r1');
});

test('fresh returns the account as is while the token still has time', function () {
    $account = xAccount(['token_expires_at' => now()->addHour()]);

    expect(X::fresh($account)->access_token)->toBe('old');
    Http::assertNothingSent();
});
