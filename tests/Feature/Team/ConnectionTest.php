<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta' => ['app_id' => 'app-id', 'app_secret' => 'app-secret', 'graph_version' => 'v26.0']]);

    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
    $this->default = $this->team->socialSets()->sole();
});

function graph(string $path): string
{
    return "https://graph.facebook.com/v26.0/{$path}*";
}

test('members can view connections but not manage them', function () {
    $account = SocialAccount::factory()->for($this->team)->create();
    $member = memberOf($this->team, 'member');

    $this->actingAs($member)->get(route('connections.index'))
        ->assertInertia(fn ($page) => $page->component('team/connections')
            ->where('canManage', false)
            ->where('accounts.0.name', $account->name)
            ->missing('accounts.0.access_token'));

    $this->actingAs($member)->get(route('connect.meta.redirect'))->assertForbidden();
    $this->actingAs($member)->put(route('connections.update', $account), ['social_set_ids' => [$this->default->id]])->assertForbidden();
    $this->actingAs($member)->delete(route('connections.destroy', $account))->assertForbidden();
});

test('the page says when meta is not configured', function () {
    config(['services.meta.app_id' => null]);

    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('metaConfigured', false));
    $this->actingAs($this->owner)->get(route('connect.meta.redirect'))->assertNotFound();
});

test('managers toggle the sets an account is in, within their own team', function () {
    $account = SocialAccount::factory()->for($this->team)->create();
    $clients = $this->team->socialSets()->create(['name' => 'Clients']);
    $foreign = User::factory()->create()->currentTeam()->socialSets()->sole();
    $admin = memberOf($this->team, 'admin');

    $this->actingAs($admin)->put(route('connections.update', $account), ['social_set_ids' => [$this->default->id, $clients->id]])->assertRedirect(route('connections.index'));
    expect($account->socialSets()->pluck('social_sets.id')->sort()->values()->all())->toBe([$this->default->id, $clients->id]);

    // The Default set is kept even when left out.
    $this->actingAs($admin)->put(route('connections.update', $account), ['social_set_ids' => [$clients->id]]);
    expect($account->socialSets()->pluck('social_sets.id')->sort()->values()->all())->toBe([$this->default->id, $clients->id]);

    $this->actingAs($admin)->put(route('connections.update', $account), ['social_set_ids' => [$foreign->id]])->assertSessionHasErrors('social_set_ids.0');
    $this->actingAs(User::factory()->create())->put(route('connections.update', $account), ['social_set_ids' => []])->assertNotFound();
});

test('a new account joins the Default set on its own', function () {
    $account = SocialAccount::factory()->for($this->team)->create();

    expect($account->socialSets()->pluck('social_sets.id')->all())->toBe([$this->default->id]);
});

test('disconnecting an account detaches it from its sets', function () {
    $account = SocialAccount::factory()->for($this->team)->create();
    $account->socialSets()->syncWithoutDetaching($this->default);

    $this->actingAs($this->owner)->delete(route('connections.destroy', $account))->assertRedirect(route('connections.index'));

    $this->assertModelMissing($account);
    expect($this->default->socialAccounts()->count())->toBe(0);
});

test('the redirect sends the user to facebook login with a state kept in the session', function () {
    $response = $this->actingAs($this->owner)->get(route('connect.meta.redirect'));

    $state = session('meta_oauth.state');
    $response->assertRedirectContains('https://www.facebook.com/v26.0/dialog/oauth?')
        ->assertRedirectContains('state='.$state)
        ->assertRedirectContains('instagram_content_publish')
        ->assertRedirectContains(urlencode(url('connect/meta/callback')));
});

test('a callback with a wrong state is refused without calling meta', function () {
    $this->actingAs($this->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'wrong', 'code' => 'abc']))
        ->assertRedirect(route('connections.index'));

    Http::assertNothingSent();
    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('a denied login just returns to connections', function () {
    $this->actingAs($this->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'right', 'error' => 'access_denied']))
        ->assertRedirect(route('connections.index'));

    Http::assertNothingSent();
});

function fakeMetaLogin(): void
{
    Http::fake([
        'https://graph.facebook.com/v26.0/oauth/access_token' => Http::sequence()
            ->push(['access_token' => 'short'])
            ->push(['access_token' => 'long', 'expires_in' => 5184000]),
        graph('me/accounts') => Http::response(['data' => [
            [
                'id' => '111', 'name' => 'Harun Page', 'access_token' => 'page-token-1',
                'picture' => ['data' => ['url' => 'https://img/p.png']],
                'instagram_business_account' => ['id' => '222', 'username' => 'harun', 'profile_picture_url' => 'https://img/i.png'],
            ],
            ['id' => '333', 'name' => 'Side Page', 'access_token' => 'page-token-2'],
            [
                'id' => '444', 'name' => 'Shop Page', 'access_token' => 'page-token-3',
                'instagram_business_account' => ['id' => '555', 'username' => 'shop'],
            ],
        ]]),
        graph('*/subscribed_apps') => Http::response(['success' => true]),
    ]);
}

function signIn($test): void
{
    fakeMetaLogin();

    $test->actingAs($test->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $test->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'right', 'code' => 'abc']))
        ->assertRedirect(route('connections.index'));
}

test('the callback saves nothing and sends the user back to connections to pick', function () {
    signIn($this);

    expect($this->team->socialAccounts()->count())->toBe(0)
        ->and(session('meta_pick.team_id'))->toBe($this->team->id);

    // The cached candidates are encrypted.
    expect(Cache::get('meta-pick:'.session('meta_pick.key')))->toBeString()->not->toContain('page-token-1');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'subscribed_apps'));
    Http::assertSent(fn ($request) => $request->method() === 'POST' && ($request['fb_exchange_token'] ?? null) === 'short' && $request['client_secret'] === 'app-secret');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'me/accounts') && $request->hasHeader('Authorization', 'Bearer long'));
    // Secrets and tokens never travel in a URL.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'app-secret') || str_contains($request->url(), 'access_token=') || str_contains($request->url(), 'long'));
});

test('signing in again drops the previous cached sign-in', function () {
    Cache::put('meta-pick:old', 'stale', now()->addMinutes(15));
    $this->withSession(['meta_pick' => ['key' => 'old', 'team_id' => $this->team->id]]);

    signIn($this);

    expect(Cache::has('meta-pick:old'))->toBeFalse()
        ->and(session('meta_pick.key'))->not->toBe('old');
});

test('the connections page offers the pending pick without tokens, flagging connected ones', function () {
    SocialAccount::factory()->for($this->team)->create(['provider' => 'facebook', 'external_id' => '111']);
    signIn($this);

    $response = $this->actingAs($this->owner)->get(route('connections.index'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('team/connections')
        ->has('pending.pages', 3)
        ->where('pending.pages.0', ['id' => '111', 'name' => 'Harun Page', 'avatar_url' => 'https://img/p.png', 'connected' => true])
        ->where('pending.pages.1.connected', false)
        ->has('pending.instagram', 2)
        ->where('pending.instagram.0', ['id' => '222', 'name' => 'harun', 'username' => 'harun', 'avatar_url' => 'https://img/i.png', 'connected' => false])
        ->where('pending.instagram.1.id', '555'));

    expect($response->getContent())->not->toContain('page-token');
});

test('the connections page has no pending pick without a sign-in', function () {
    $this->actingAs($this->owner)->get(route('connections.index'))
        ->assertInertia(fn ($page) => $page->where('pending', null));
});

test('cancelling forgets the pending sign-in', function () {
    signIn($this);
    $key = session('meta_pick.key');

    $this->actingAs($this->owner)->delete(route('connect.meta.cancel'))->assertRedirect(route('connections.index'));

    expect(Cache::has('meta-pick:'.$key))->toBeFalse()
        ->and(session('meta_pick'))->toBeNull();
    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('pending', null));
    $this->actingAs($this->owner)->post(route('connect.meta.store'), ['facebook' => ['111']])
        ->assertInertiaFlash('toast.message', 'That Facebook sign-in expired. Please connect again.');
    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('reconnecting refreshes the tokens of already-connected accounts before the picker opens', function () {
    $fb = SocialAccount::factory()->for($this->team)->create(['provider' => 'facebook', 'external_id' => '111', 'name' => 'Old name', 'access_token' => 'stale']);
    $ig = SocialAccount::factory()->for($this->team)->create(['provider' => 'instagram', 'external_id' => '222', 'access_token' => 'stale']);
    $threads = SocialAccount::factory()->for($this->team)->create(['provider' => 'threads', 'external_id' => '111', 'access_token' => 'threads-token']);
    fakeMetaLogin();

    $this->actingAs($this->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'right', 'code' => 'abc']))
        ->assertRedirect(route('connections.index'))
        ->assertInertiaFlash('toast.message', 'Refreshed 2 connected accounts.');

    // Only the connected ones are written; the rest wait for the picker, and closing it keeps the refresh.
    expect($this->team->socialAccounts()->count())->toBe(3)
        ->and($fb->fresh()->access_token)->toBe('page-token-1')
        ->and($fb->fresh()->name)->toBe('Harun Page')
        ->and($ig->fresh()->access_token)->toBe('page-token-1')
        ->and($ig->fresh()->username)->toBe('harun')
        ->and($threads->fresh()->access_token)->toBe('threads-token');

    $this->actingAs($this->owner)->delete(route('connect.meta.cancel'))->assertRedirect(route('connections.index'));
    expect($fb->fresh()->access_token)->toBe('page-token-1');
});

test('storing saves only the picked accounts and subscribes only their pages', function () {
    $existing = SocialAccount::factory()->for($this->team)->create(['provider' => 'facebook', 'external_id' => '111', 'name' => 'Old name']);
    signIn($this);

    $this->actingAs($this->owner)
        ->post(route('connect.meta.store'), ['facebook' => ['111'], 'instagram' => ['555']])
        ->assertRedirect(route('connections.index'))
        ->assertInertiaFlash('toast.message', 'Connected 2 accounts.');

    expect($this->team->socialAccounts()->count())->toBe(2)
        ->and($existing->fresh()->name)->toBe('Harun Page')
        ->and($existing->fresh()->access_token)->toBe('page-token-1');

    $ig = $this->team->socialAccounts()->where('provider', 'instagram')->sole();
    expect($ig->external_id)->toBe('555')
        ->and($ig->username)->toBe('shop')
        ->and($ig->access_token)->toBe('page-token-3')
        ->and($ig->meta)->toBe(['page_id' => '444']);

    // Stored encrypted, never in plain text.
    expect(DB::table('social_accounts')->where('id', $ig->id)->value('access_token'))->not->toBe('page-token-3');

    Http::assertSent(fn ($request) => str_contains($request->url(), '111/subscribed_apps'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '444/subscribed_apps'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '333/subscribed_apps'));

    // The sign-in is used up.
    $this->actingAs($this->owner)->post(route('connect.meta.store'), ['facebook' => ['333']])
        ->assertInertiaFlash('toast.message', 'That Facebook sign-in expired. Please connect again.');
    expect($this->team->socialAccounts()->count())->toBe(2);
});

test('storing refuses ids facebook did not return, or an empty pick', function () {
    signIn($this);

    $this->actingAs($this->owner)->post(route('connect.meta.store'), ['facebook' => ['999']])->assertSessionHasErrors('facebook.0');
    $this->actingAs($this->owner)->post(route('connect.meta.store'), ['instagram' => ['111']])->assertSessionHasErrors('instagram.0');
    $this->actingAs($this->owner)->post(route('connect.meta.store'), [])->assertSessionHasErrors('facebook');

    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('an expired sign-in sends the user back to connections', function () {
    signIn($this);
    Cache::flush();

    $this->actingAs($this->owner)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('pending', null));
    $this->actingAs($this->owner)->post(route('connect.meta.store'), ['facebook' => ['111']])
        ->assertRedirect(route('connections.index'))
        ->assertInertiaFlash('toast.message', 'That Facebook sign-in expired. Please connect again.');

    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('members who cannot manage the team cannot see, cancel or store a pick', function () {
    signIn($this);
    $member = memberOf($this->team, 'member');

    $this->actingAs($member)->get(route('connections.index'))->assertInertia(fn ($page) => $page->where('pending', null));
    $this->actingAs($member)->delete(route('connect.meta.cancel'))->assertForbidden();
    $this->actingAs($member)->post(route('connect.meta.store'), ['facebook' => ['111']])->assertForbidden();

    expect($this->team->socialAccounts()->count())->toBe(0)
        ->and(Cache::has('meta-pick:'.session('meta_pick.key')))->toBeTrue();
});

test('a meta error during the callback is shown, not thrown', function () {
    Http::fake(['https://graph.facebook.com/v26.0/oauth/access_token' => Http::response(['error' => ['message' => 'Invalid code']], 400)]);

    $this->actingAs($this->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'right', 'code' => 'bad']))
        ->assertRedirect(route('connections.index'));

    expect($this->team->socialAccounts()->count())->toBe(0);
});

test('a connection failure during the callback shows a message without the secret', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 6 for https://graph.facebook.com/v26.0/oauth/access_token?client_secret=app-secret'));

    $this->actingAs($this->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'right', 'code' => 'abc']))
        ->assertRedirect(route('connections.index'))
        ->assertInertiaFlash('toast.message', 'Could not connect to Facebook: Meta: could not reach the Graph API.');
});

test('a page that cannot be subscribed to webhooks is named in a warning', function () {
    // Registered before signIn()'s fakes, so it wins over their wildcard.
    Http::fake([graph('111/subscribed_apps') => Http::response(['error' => ['message' => 'Nope.']], 403)]);
    signIn($this);

    $this->actingAs($this->owner)
        ->post(route('connect.meta.store'), ['facebook' => ['111', '333']])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Connected 2 accounts. Comment automations will not run on Harun Page until you connect again.');
});
