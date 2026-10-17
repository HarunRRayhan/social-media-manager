<?php

use App\Jobs\RunCommentAutomation;
use App\Models\CommentAutomation;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

function automationFor(SocialAccount $account, array $attributes = []): CommentAutomation
{
    return $account->team->commentAutomations()->create([
        'social_account_id' => $account->id,
        'name' => 'Guide',
        'keywords' => ['Guide'],
        'dm_message' => 'Here is the guide',
        ...$attributes,
    ]);
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

function igComment(string $text, string $from = 'iguser9', string $commentId = 'igc1', string $mediaId = 'media1'): array
{
    return ['object' => 'instagram', 'entry' => [['id' => 'ig1', 'time' => 1, 'changes' => [[
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

    Queue::assertPushed(RunCommentAutomation::class, fn ($job) => $job->automationId === $automation->id && $job->commentId === 'c1' && $job->commenterId === 'user9');
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
    $automation = automationFor($this->page, ['post_remote_id' => 'page1_5']);

    deliver(pageComment('guide', commentId: 'c1', postId: 'page1_6'));
    deliver(pageComment('guide', commentId: 'c2', postId: 'page1_5'));

    Http::assertSentCount(1);
    expect($automation->runs()->pluck('comment_id')->all())->toBe(['c2']);
});

test('a redelivered webhook never sends a second dm', function () {
    Http::fake([graphUrl('page1/messages') => ['message_id' => 'm1']]);
    $automation = automationFor($this->page);

    deliver(pageComment('guide'));
    deliver(pageComment('guide'));
    RunCommentAutomation::dispatchSync($automation->id, 'c1', 'user9');

    Http::assertSentCount(1);
    expect($automation->runs()->count())->toBe(1)
        ->and((new RunCommentAutomation($automation->id, 'c1', 'user9'))->tries)->toBe(1);
});

test('an automation turned off after the webhook is skipped', function () {
    $automation = automationFor($this->page);
    $automation->update(['active' => false]);

    RunCommentAutomation::dispatchSync($automation->id, 'c1', 'user9');

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
    $this->actingAs($member)->post(route('automations.subscribe'))->assertForbidden();
});

test('managers create, edit, toggle and delete automations within their team', function () {
    $admin = memberOf($this->team, 'admin');
    $foreign = SocialAccount::factory()->create();
    $data = ['social_account_id' => $this->ig->id, 'name' => 'Link', 'keywords' => ['link', 'url'], 'dm_message' => 'Here', 'public_reply' => null, 'active' => true];

    $this->actingAs($admin)->post(route('automations.store'), $data)->assertRedirect(route('automations.index'));
    $automation = $this->team->commentAutomations()->sole();
    expect($automation)->keywords->toBe(['link', 'url'])->post_remote_id->toBeNull()->active->toBeTrue();

    $this->actingAs($admin)->put(route('automations.update', $automation), [...$data, 'active' => false, 'post_remote_id' => 'media1']);
    expect($automation->fresh())->active->toBeFalse()->post_remote_id->toBe('media1');

    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'social_account_id' => $foreign->id])->assertSessionHasErrors('social_account_id');
    $this->actingAs($admin)->post(route('automations.store'), [...$data, 'keywords' => []])->assertSessionHasErrors('keywords');
    $this->actingAs(User::factory()->create())->delete(route('automations.destroy', $automation))->assertNotFound();

    $this->actingAs($admin)->delete(route('automations.destroy', $automation))->assertRedirect(route('automations.index'));
    $this->assertModelMissing($automation);
});

test('enabling comment automation subscribes every connected page to the webhooks', function () {
    Http::fake([graphUrl('page1/subscribed_apps') => ['success' => true]]);

    $this->actingAs($this->owner)->post(route('automations.subscribe'))->assertRedirect(route('automations.index'));

    Http::assertSent(fn ($r) => $r->url() === graphUrl('page1/subscribed_apps') && $r['subscribed_fields'] === 'feed,messages' && $r->hasHeader('Authorization', 'Bearer pt'));
    Http::assertSentCount(1);
});

test('the webhook skips csrf, which the web group enforces in production', function () {
    $route = app('router')->getRoutes()->match(Request::create('/webhooks/meta', 'POST'));

    expect(app('router')->gatherRouteMiddleware($route))->not->toContain(PreventRequestForgery::class);
});
