<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $this->actingAs($admin)->put(route('connections.update', $account), ['social_set_ids' => [$clients->id]]);
    expect($account->socialSets()->pluck('social_sets.id')->all())->toBe([$clients->id]);

    $this->actingAs($admin)->put(route('connections.update', $account), ['social_set_ids' => [$foreign->id]])->assertSessionHasErrors('social_set_ids.0');
    $this->actingAs(User::factory()->create())->put(route('connections.update', $account), ['social_set_ids' => []])->assertNotFound();
});

test('disconnecting an account detaches it from its sets', function () {
    $account = SocialAccount::factory()->for($this->team)->create();
    $account->socialSets()->attach($this->default);

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

test('the callback stores one account per page and per linked instagram account', function () {
    Http::fake([
        graph('oauth/access_token') => Http::sequence()
            ->push(['access_token' => 'short'])
            ->push(['access_token' => 'long', 'expires_in' => 5184000]),
        graph('me/accounts') => Http::response(['data' => [
            [
                'id' => '111', 'name' => 'Harun Page', 'access_token' => 'page-token-1',
                'picture' => ['data' => ['url' => 'https://img/p.png']],
                'instagram_business_account' => ['id' => '222', 'username' => 'harun', 'profile_picture_url' => 'https://img/i.png'],
            ],
            ['id' => '333', 'name' => 'Side Page', 'access_token' => 'page-token-2'],
        ]]),
    ]);

    $existing = SocialAccount::factory()->for($this->team)->create(['provider' => 'facebook', 'external_id' => '111', 'name' => 'Old name']);

    $this->actingAs($this->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'right', 'code' => 'abc']))
        ->assertRedirect(route('connections.index'));

    expect($this->team->socialAccounts()->count())->toBe(3)
        ->and($existing->fresh()->name)->toBe('Harun Page')
        ->and($existing->fresh()->access_token)->toBe('page-token-1');

    $ig = $this->team->socialAccounts()->where('provider', 'instagram')->sole();
    expect($ig->external_id)->toBe('222')
        ->and($ig->username)->toBe('harun')
        ->and($ig->access_token)->toBe('page-token-1')
        ->and($ig->meta)->toBe(['page_id' => '111']);

    // Stored encrypted, never in plain text.
    expect(DB::table('social_accounts')->where('id', $ig->id)->value('access_token'))->not->toBe('page-token-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'fb_exchange_token=short'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'me/accounts') && $request['access_token'] === 'long');
});

test('a meta error during the callback is shown, not thrown', function () {
    Http::fake([graph('oauth/access_token') => Http::response(['error' => ['message' => 'Invalid code']], 400)]);

    $this->actingAs($this->owner)
        ->withSession(['meta_oauth' => ['state' => 'right', 'team_id' => $this->team->id]])
        ->get(route('connect.meta.callback', ['state' => 'right', 'code' => 'bad']))
        ->assertRedirect(route('connections.index'));

    expect($this->team->socialAccounts()->count())->toBe(0);
});
