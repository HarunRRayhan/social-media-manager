<?php

use App\Jobs\RunCommentAutomation;
use App\Models\CommentAutomation;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialSet;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta' => ['app_id' => 'app-id', 'app_secret' => 'app-secret', 'graph_version' => 'v26.0', 'webhook_verify_token' => 'verify-me']]);

    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
    $this->page = SocialAccount::factory()->for($this->team)->create(['external_id' => 'page1', 'name' => 'Page One', 'access_token' => 'pt']);
    $this->ig = SocialAccount::factory()->for($this->team)->instagram()->create(['external_id' => 'ig1', 'name' => 'harun', 'access_token' => 'pt', 'meta' => ['page_id' => 'page1']]);
});

function graphUrl(string $path): string
{
    return "https://graph.facebook.com/v26.0/{$path}";
}

/** @param  SocialAccount|list<SocialAccount>  $accounts */
function automationFor(SocialAccount|array $accounts, array $attributes = []): CommentAutomation
{
    $accounts = collect(is_array($accounts) ? $accounts : [$accounts]);
    $automation = $accounts->first()->team->commentAutomations()->create([
        'name' => 'Guide',
        'keywords' => ['Guide'],
        'dm_message' => 'Here is the guide',
        ...$attributes,
    ]);
    $automation->accounts()->attach($accounts->pluck('id'));

    return $automation;
}

/** POST a webhook delivery signed with the app secret, unless another signature is given. */
function deliver(array $payload, ?string $signature = null)
{
    $body = json_encode($payload);

    return test()->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body);
}

function pageComment(string $text, string $from = 'user9', string $commentId = 'c1', string $postId = 'page1_5', string $page = 'page1'): array
{
    return ['object' => 'page', 'entry' => [['id' => $page, 'time' => 1, 'changes' => [[
        'field' => 'feed',
        'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => $commentId, 'post_id' => $postId, 'from' => ['id' => $from, 'name' => 'Someone'], 'message' => $text],
    ]]]]];
}

function igComment(string $text, string $from = 'iguser9', string $commentId = 'igc1', string $mediaId = 'media1', string $account = 'ig1'): array
{
    return ['object' => 'instagram', 'entry' => [['id' => $account, 'time' => 1, 'changes' => [[
        'field' => 'comments',
        'value' => ['id' => $commentId, 'text' => $text, 'from' => ['id' => $from, 'username' => 'someone'], 'media' => ['id' => $mediaId]],
    ]]]]];
}

test('the webhook handshake echoes the challenge only for the right verify token', function () {
    $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')->assertOk()->assertContent('12345');
    $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();

    config(['services.meta.webhook_verify_token' => null]);
    $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=&hub.challenge=12345')->assertForbidden();
});

test('a delivery with a bad or missing signature is rejected', function () {
    Queue::fake();
    automationFor($this->page);

    deliver(pageComment('guide please'), 'sha256='.hash_hmac('sha256', 'other body', 'app-secret'))->assertForbidden();
    deliver(pageComment('guide please'), '')->assertForbidden();

    Queue::assertNothingPushed();
});

test('a signed facebook comment with a keyword queues the automation', function () {
    Queue::fake();
    $automation = automationFor($this->page);

    deliver(pageComment('Can I get the GUIDE?'))->assertOk();

    Queue::assertPushed(RunCommentAutomation::class, fn ($job) => $job->automationId === $automation->id && $job->accountId === $this->page->id && $job->commentId === 'c1' && $job->commenterId === 'user9');
});

test('a facebook comment gets a private reply from the page and the public reply', function () {
    Http::fake([graphUrl('page1/messages') => ['recipient_id' => 'psid', 'message_id' => 'm1'], graphUrl('c1/comments') => ['id' => 'r1']]);
    $automation = automationFor($this->page, ['public_reply' => 'Check your DMs']);

    deliver(pageComment('guide'))->assertOk();

    Http::assertSent(fn ($r) => $r->url() === graphUrl('page1/messages') && $r['recipient'] === ['comment_id' => 'c1']
        && $r['message'] === ['text' => 'Here is the guide'] && $r->hasHeader('Authorization', 'Bearer pt'));
    Http::assertSent(fn ($r) => $r->url() === graphUrl('c1/comments') && $r['message'] === 'Check your DMs');
    expect($automation->runs()->sole())->status->toBe('sent')->commenter_id->toBe('user9')->error->toBeNull();
});

test('an instagram comment gets a private reply through the linked page and the public reply', function () {
    Http::fake([graphUrl('page1/messages') => ['recipient_id' => 'igsid', 'message_id' => 'm1'], graphUrl('igc1/replies') => ['id' => 'r1']]);
    $automation = automationFor($this->ig, ['public_reply' => 'Sent!']);

    deliver(igComment('send the guide'))->assertOk();

    Http::assertSent(fn ($r) => $r->url() === graphUrl('page1/messages') && $r['recipient'] === ['comment_id' => 'igc1']);
    Http::assertSent(fn ($r) => $r->url() === graphUrl('igc1/replies') && $r['message'] === 'Sent!');
    expect($automation->runs()->sole()->status)->toBe('sent');
});

test('a failed dm is recorded without the token', function () {
    $this->page->update(['access_token' => 'secret-page-token']);
    Http::fake([graphUrl('page1/messages') => Http::response(['error' => ['message' => 'This message is sent outside of allowed window.']], 400)]);
    $automation = automationFor($this->page, ['public_reply' => 'Check your DMs']);

    deliver(pageComment('guide'))->assertOk();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'c1/comments'));
    expect($automation->runs()->sole())->status->toBe('failed')
        ->error->toBe('Meta: This message is sent outside of allowed window.')->not->toContain('secret-page-token');
});

test('a comment without a keyword, the account own comment, and an inactive automation send nothing', function () {
    automationFor($this->page);
    automationFor($this->ig, ['active' => false]);

    deliver(pageComment('nice post'))->assertOk();
    deliver(pageComment('guide', from: 'page1'))->assertOk();
    deliver(igComment('guide'))->assertOk();
    deliver(igComment('guide', from: 'ig1'))->assertOk();

    Http::assertNothingSent();
    expect(CommentAutomation::query()->withCount('runs')->get()->sum('runs_count'))->toBe(0);
});

test('an automation limited to one post ignores comments on other posts', function () {
    Http::fake([graphUrl('page1/messages') => ['message_id' => 'm1']]);
    $automation = automationFor([$this->page, $this->ig], ['post_remote_ids' => ['page1_5', 'media2']]);

    deliver(pageComment('guide', commentId: 'c1', postId: 'page1_6'));
    deliver(pageComment('guide', commentId: 'c2', postId: 'page1_5'));
    deliver(igComment('guide', commentId: 'igc1', mediaId: 'media1'));
    deliver(igComment('guide', commentId: 'igc2', mediaId: 'media2'));

    Http::assertSentCount(2);
    expect($automation->runs()->orderBy('id')->pluck('comment_id')->all())->toBe(['c2', 'igc2']);
});

test('a redelivered webhook never sends a second dm', function () {
    Http::fake([graphUrl('page1/messages') => ['message_id' => 'm1']]);
    $automation = automationFor($this->page);

    deliver(pageComment('guide'));
    deliver(pageComment('guide'));
    RunCommentAutomation::dispatchSync($automation->id, $this->page->id, 'c1', 'user9');

    Http::assertSentCount(1);
    expect($automation->runs()->count())->toBe(1)
        ->and((new RunCommentAutomation($automation->id, $this->page->id, 'c1', 'user9'))->tries)->toBe(1);
});

test('an automation turned off after the webhook is skipped', function () {
    $automation = automationFor($this->page);
    $automation->update(['active' => false]);

    RunCommentAutomation::dispatchSync($automation->id, $this->page->id, 'c1', 'user9');

    Http::assertNothingSent();
    expect($automation->runs()->sole()->status)->toBe('skipped');
});

test('another team automation never fires for a page it does not own', function () {
    $other = User::factory()->create()->currentTeam();
    $otherPage = SocialAccount::factory()->for($other)->create(['external_id' => 'page2']);
    Queue::fake();
    $mine = automationFor($this->page);
    $theirs = automationFor($otherPage);

    deliver(pageComment('guide'));

    Queue::assertPushed(RunCommentAutomation::class, 1);
    Queue::assertPushed(RunCommentAutomation::class, fn ($job) => $job->automationId === $mine->id);
    Queue::assertNotPushed(RunCommentAutomation::class, fn ($job) => $job->automationId === $theirs->id);
});

test('members view automations but only managers change them', function () {
    $automation = automationFor($this->page);
    $member = memberOf($this->team, 'member');

    $this->actingAs($member)->get(route('automations.index'))
        ->assertInertia(fn ($page) => $page->component('team/automations')
            ->where('canManage', false)
            ->where('automations.0.name', 'Guide')
            ->missing('accounts.0.access_token'));

    $this->actingAs($member)->post(route('automations.store'), [])->assertForbidden();
    $this->actingAs($member)->put(route('automations.update', $automation), [])->assertForbidden();
    $this->actingAs($member)->delete(route('automations.destroy', $automation))->assertForbidden();
});

test('managers create, edit, toggle and delete automations within their team', function () {
    $admin = memberOf($this->team, 'admin');
    $foreign = SocialAccount::factory()->create();
    $data = ['type' => 'comment_to_dm', 'social_account_ids' => [$this->page->id, $this->ig->id], 'name' => 'Link', 'keywords' => ['link', 'url'], 'dm_message' => 'Here', 'public_reply' => null, 'active' => true];

    $this->actingAs($admin)->post(route('automations.store'), $data)->assertRedirect(route('automations.index'));
    $automation = $this->team->commentAutomations()->sole();
    expect($automation)->type->toBe('comment_to_dm')->keywords->toBe(['link', 'url'])->post_remote_ids->toBeNull()->active->toBeTrue()
        ->and($automation->accounts()->pluck('social_accounts.id')->sort()->values()->all())->toBe([$this->page->id, $this->ig->id]);

    $this->actingAs($admin)->put(route('automations.update', $automation), [...$data, 'social_account_ids' => [$this->ig->id], 'active' => false, 'post_remote_ids' => ['media1']]);
    expect($automation->fresh())->active->toBeFalse()->post_remote_ids->toBe(['media1'])
        ->and($automation->accounts()->pluck('social_accounts.id')->all())->toBe([$this->ig->id]);

    $this->actingAs($admin)->put(route('automations.update', $automation), [...$data, 'post_remote_ids' => null]);
    expect($automation->fresh()->post_remote_ids)->toBeNull();

    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'social_account_ids' => [$this->page->id, $foreign->id]])->assertSessionHasErrors('social_account_ids.1');
    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'social_account_ids' => []])->assertSessionHasErrors('social_account_ids');
    // Only Meta sends comment webhooks, so a Threads, LinkedIn or Bluesky account can't be picked.
    $threads = SocialAccount::factory()->for($this->team)->create(['provider' => 'threads']);
    $linkedin = SocialAccount::factory()->for($this->team)->create(['provider' => 'linkedin', 'external_id' => 'urn:li:person:abc']);
    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'social_account_ids' => [$threads->id]])->assertSessionHasErrors('social_account_ids.0');
    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'social_account_ids' => [$linkedin->id]])->assertSessionHasErrors('social_account_ids.0');
    $this->actingAs($admin)->get(route('automations.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('accounts', fn ($accounts) => collect($accounts)->doesntContain('id', $threads->id) && collect($accounts)->doesntContain('id', $linkedin->id)));
    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'keywords' => []])->assertSessionHasErrors('keywords');
    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'type' => 'story_reply'])->assertSessionHasErrors('type');
    // Picking posts but none must not widen to every post.
    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'post_remote_ids' => []])->assertSessionHasErrors('post_remote_ids');
    expect($this->team->commentAutomations()->count())->toBe(1);
    $this->actingAs(User::factory()->create())->delete(route('automations.destroy', $automation))->assertNotFound();

    $this->actingAs($admin)->delete(route('automations.destroy', $automation))->assertRedirect(route('automations.index'));
    $this->assertModelMissing($automation);
    $this->assertDatabaseCount('comment_automation_social_account', 0);
});

test('one automation on two accounts runs for comments on either, as the account that received it', function () {
    Http::fake([graphUrl('page1/messages') => ['message_id' => 'm1'], graphUrl('page2/messages') => ['message_id' => 'm2']]);
    $other = SocialAccount::factory()->for($this->team)->create(['external_id' => 'page2', 'access_token' => 'pt2']);
    $automation = automationFor([$this->page, $other]);

    deliver(pageComment('guide', commentId: 'c1', page: 'page1'));
    deliver(pageComment('guide', commentId: 'c2', postId: 'page2_1', page: 'page2'));

    Http::assertSent(fn ($r) => $r->url() === graphUrl('page1/messages') && $r['recipient'] === ['comment_id' => 'c1'] && $r->hasHeader('Authorization', 'Bearer pt'));
    Http::assertSent(fn ($r) => $r->url() === graphUrl('page2/messages') && $r['recipient'] === ['comment_id' => 'c2'] && $r->hasHeader('Authorization', 'Bearer pt2'));
    Http::assertSentCount(2);
    expect($automation->runs()->pluck('status')->all())->toBe(['sent', 'sent']);
});

test('an account removed from the automation after the webhook is skipped', function () {
    $automation = automationFor([$this->page, $this->ig]);
    $automation->accounts()->detach($this->page);

    RunCommentAutomation::dispatchSync($automation->id, $this->page->id, 'c1', 'user9');

    Http::assertNothingSent();
    expect($automation->runs()->sole())->status->toBe('skipped')->error->toBe('The account was removed from the automation.');
});

test('the page lists the accounts, the attached accounts, and the newest published posts of the team', function () {
    $automation = automationFor([$this->page, $this->ig], ['post_remote_ids' => ['media1']]);
    $post = Post::factory()->for($this->team)->create(['social_set_id' => SocialSet::factory()->for($this->team), 'body' => 'Free guide inside', 'status' => 'published']);
    $post->targets()->create(['social_account_id' => $this->ig->id, 'status' => 'published', 'remote_id' => 'media1', 'published_at' => now()->subDay()]);
    $post->targets()->create(['social_account_id' => $this->page->id, 'status' => 'failed']);
    $foreign = Post::factory()->create(['status' => 'published']);
    $foreign->targets()->create(['social_account_id' => SocialAccount::factory()->create()->id, 'status' => 'published', 'remote_id' => 'theirs', 'published_at' => now()]);

    $this->actingAs($this->owner)->get(route('automations.index'))
        ->assertInertia(fn ($page) => $page->component('team/automations')
            ->where('automations.0.social_account_ids', [$this->page->id, $this->ig->id])
            ->where('automations.0.post_remote_ids', ['media1'])
            ->has('automations.0.accounts', 2)
            ->has('posts', 1)
            ->where('posts.0.remote_id', 'media1')
            ->where('posts.0.provider', 'instagram')
            ->where('posts.0.excerpt', 'Free guide inside'));
});

test('a job queued before automations had many accounts still sends as its one account', function () {
    Http::fake([graphUrl('page1/messages') => ['recipient_id' => 'psid', 'message_id' => 'm1']]);
    $automation = automationFor($this->page);

    // An old payload unserializes without the accountId property.
    $job = (new ReflectionClass(RunCommentAutomation::class))->newInstanceWithoutConstructor();
    $job->automationId = $automation->id;
    $job->commentId = 'c1';
    $job->commenterId = 'user9';
    $job->handle();

    Http::assertSent(fn ($r) => $r->url() === graphUrl('page1/messages'));
    expect($automation->runs()->sole()->status)->toBe('sent');
});

test('the old enable button route is gone', function () {
    $this->actingAs($this->owner)->post('/team/automations/subscribe')->assertNotFound();
});

test('the webhook skips csrf, which the web group enforces in production', function () {
    $route = app('router')->getRoutes()->match(Request::create('/webhooks/meta', 'POST'));

    expect(app('router')->gatherRouteMiddleware($route))->not->toContain(PreventRequestForgery::class);
});

it('refuses an active automation that would answer the same comments as another', function () {
    automationFor($this->page, ['name' => 'Guide', 'keywords' => ['info'], 'post_remote_ids' => ['page1_5']]);
    $data = ['social_account_ids' => [$this->page->id], 'type' => 'comment_to_dm', 'name' => 'Other', 'keywords' => ['INFORMATION'], 'dm_message' => 'Hi', 'active' => true];

    // All posts overlaps page1_5; "INFORMATION" contains "info".
    $this->actingAs($this->owner)->post('/team/automations', $data)->assertSessionHasErrors('keywords');
    // Same keyword on a different post is fine, and so is an inactive one on the same post.
    $this->actingAs($this->owner)->post('/team/automations', [...$data, 'post_remote_ids' => ['page1_6']])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/team/automations', [...$data, 'name' => 'Off', 'active' => false])->assertSessionHasNoErrors();
    // Turning that one on now clashes.
    $off = $this->team->commentAutomations()->where('name', 'Off')->sole();
    $this->actingAs($this->owner)->put("/team/automations/{$off->id}", [...$data, 'name' => 'Off', 'active' => true])->assertSessionHasErrors('keywords');
    // A different account does not clash.
    $this->actingAs($this->owner)->post('/team/automations', [...$data, 'name' => 'IG', 'social_account_ids' => [$this->ig->id]])->assertSessionHasNoErrors();
});

it('lets an already overlapping automation be edited without touching its scope', function () {
    $a = automationFor($this->page, ['name' => 'A', 'keywords' => ['info']]);
    automationFor($this->page, ['name' => 'B', 'keywords' => ['info']]);

    $this->actingAs($this->owner)->put("/team/automations/{$a->id}", [
        'social_account_ids' => [$this->page->id], 'type' => 'comment_to_dm', 'name' => 'A', 'keywords' => ['info'], 'dm_message' => 'New text', 'active' => true,
    ])->assertSessionHasNoErrors();

    expect($a->fresh()->dm_message)->toBe('New text');
});
