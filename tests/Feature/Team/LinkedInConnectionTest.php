<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.linkedin' => ['client_id' => 'li-app', 'client_secret' => 'li-secret']]);

    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
});

function fakeLinkedInLogin(array $token = []): void
{
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => ['access_token' => 'li-token', 'expires_in' => 5184000, ...$token],
        'https://api.linkedin.com/v2/userinfo' => ['sub' => 'abc123', 'name' => 'Harun R', 'picture' => 'https://media.licdn.com/p.jpg'],
    ]);
}

function linkedinCallback(array $query = ['state' => 'right', 'code' => 'abc'])
{
    return test()->actingAs(test()->owner)
        ->withSession(['linkedin_oauth' => ['state' => 'right', 'team_id' => test()->team->id]])
        ->get(route('connect.linkedin.callback', $query));
}

test('the page says when linkedin is not configured, and the redirect is off', function () {
    config(['services.linkedin.client_id' => null]);

    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('linkedinConfigured', false));
    $this->actingAs($this->owner)->get(route('connect.linkedin.redirect'))->assertNotFound();
});

test('members cannot start a linkedin connection', function () {
    $this->actingAs(memberOf($this->team, 'member'))->get(route('connect.linkedin.redirect'))->assertForbidden();
});

test('the redirect sends the user to linkedin with the posting scope and a state kept in the session', function () {
    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('linkedinConfigured', true));

    $this->actingAs($this->owner)->get(route('connect.linkedin.redirect'))
        ->assertRedirectContains('https://www.linkedin.com/oauth/v2/authorization?')
        ->assertRedirectContains('state='.session('linkedin_oauth.state'))
        ->assertRedirectContains('scope=openid+profile+email+w_member_social')
        ->assertRedirectContains(urlencode(url('connect/linkedin/callback')));

    expect(url('connect/linkedin/callback'))->toEndWith('/connect/linkedin/callback');
});

test('a callback with a wrong state is refused without calling linkedin', function () {
    linkedinCallback(['state' => 'wrong', 'code' => 'abc'])->assertRedirect(route('connections.index'));

    Http::assertNothingSent();
    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('a member cannot finish a sign-in for a team they do not manage', function () {
    $member = memberOf($this->team, 'member');

    $this->actingAs($member)
        ->withSession(['linkedin_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.linkedin.callback', ['state' => 'right', 'code' => 'abc']))
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a cancelled sign-in stores nothing', function () {
    linkedinCallback(['state' => 'right', 'error' => 'user_cancelled_authorize'])
        ->assertInertiaFlash('toast.message', 'LinkedIn connection was cancelled.');

    Http::assertNothingSent();
});

test('the callback stores the member urn with an encrypted 60-day token', function () {
    fakeLinkedInLogin();

    linkedinCallback()->assertRedirect(route('connections.index'))
        ->assertInertiaFlash('toast.message', 'Connected Harun R on LinkedIn.');

    $account = $this->team->socialAccounts()->sole();
    expect($account)->provider->toBe('linkedin')->external_id->toBe('urn:li:person:abc123')->name->toBe('Harun R')
        ->avatar_url->toBe('https://media.licdn.com/p.jpg')->access_token->toBe('li-token')->meta->toBeNull()
        ->and($account->token_expires_at->between(now()->addDays(59), now()->addDays(61)))->toBeTrue()
        ->and($account->getRawOriginal('access_token'))->not->toBe('li-token');

    Http::assertSent(fn ($r) => $r->url() === 'https://www.linkedin.com/oauth/v2/accessToken' && $r['grant_type'] === 'authorization_code'
        && $r['code'] === 'abc' && $r['client_secret'] === 'li-secret' && $r['redirect_uri'] === url('connect/linkedin/callback'));
    Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/v2/userinfo' && $r->hasHeader('Authorization', 'Bearer li-token'));
});

test('signing in again updates the same account and keeps a refresh token encrypted', function () {
    $existing = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'external_id' => 'urn:li:person:abc123', 'access_token' => 'old']);
    fakeLinkedInLogin(['refresh_token' => 'li-refresh']);

    linkedinCallback();

    $account = $this->team->socialAccounts()->sole();
    expect($account)->id->toBe($existing->id)->access_token->toBe('li-token')
        ->and(Crypt::decryptString($account->meta['refresh_token']))->toBe('li-refresh');
});

test('a failed exchange shows the error and stores nothing', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_request', 'error_description' => 'Bad code.'], 400)]);

    linkedinCallback()->assertInertiaFlash('toast.message', 'Could not connect to LinkedIn: LinkedIn: Bad code.');

    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('only tokens with a refresh token near expiry are renewed', function () {
    Http::fake(['https://www.linkedin.com/oauth/v2/accessToken' => ['access_token' => 'fresh', 'expires_in' => 5184000]]);
    $due = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'access_token' => 'aging', 'token_expires_at' => now()->addDays(5), 'meta' => ['refresh_token' => Crypt::encryptString('r1')]]);
    $young = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'access_token' => 'young', 'token_expires_at' => now()->addDays(50), 'meta' => ['refresh_token' => Crypt::encryptString('r2')]]);
    $noRefresh = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'access_token' => 'plain', 'token_expires_at' => now()->addDays(5)]);

    $this->artisan('linkedin:refresh-tokens')->assertSuccessful();

    expect($due->fresh()->access_token)->toBe('fresh')
        ->and(Crypt::decryptString($due->fresh()->meta['refresh_token']))->toBe('r1')
        ->and($young->fresh()->access_token)->toBe('young')
        ->and($noRefresh->fresh()->access_token)->toBe('plain');
    Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1');
    Http::assertSentCount(1);
});

test('the connections page flags accounts whose token expires within a week', function () {
    $soon = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'name' => 'A', 'token_expires_at' => now()->addDays(3)]);
    $past = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'name' => 'B', 'token_expires_at' => now()->subDay()]);
    $fine = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'name' => 'C', 'token_expires_at' => now()->addDays(30)]);
    $never = SocialAccount::factory()->for($this->team)->create(['provider' => 'facebook', 'name' => 'D', 'token_expires_at' => null]);

    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('accounts', fn ($accounts) => collect($accounts)->pluck('reconnect', 'id')->all() === [
        $never->id => false, $soon->id => true, $past->id => true, $fine->id => false,
    ]));
});
