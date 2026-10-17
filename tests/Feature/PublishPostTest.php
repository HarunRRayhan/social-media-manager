<?php

use App\Jobs\PublishPost;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v26.0']);

    $this->team = User::factory()->create()->currentTeam();
    $this->set = $this->team->socialSets()->sole();
    $this->page = SocialAccount::factory()->for($this->team)->create(['external_id' => 'page1', 'name' => 'Page One', 'access_token' => 'pt']);
    $this->ig = SocialAccount::factory()->for($this->team)->instagram()->create(['external_id' => 'ig1', 'name' => 'harun', 'access_token' => 'pt']);
    $this->set->socialAccounts()->attach([$this->page->id, $this->ig->id]);
});

function g(string $path): string
{
    return "https://graph.facebook.com/v26.0/{$path}";
}

function queuedPost(array $attributes): Post
{
    return Post::factory()->for(test()->set)->create(['status' => 'queued', ...$attributes]);
}

test('a facebook text post goes to the page feed', function () {
    Http::fake([g('page1/feed') => ['id' => 'page1_42']]);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hello']);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === g('page1/feed') && $r['message'] === 'Hello' && $r->hasHeader('Authorization', 'Bearer pt') && ! str_contains($r->url().$r->body(), 'pt'));
    expect($post->fresh())->status->toBe('published')->error->toBeNull()->published_at->not->toBeNull();
    expect($post->targets()->sole())->status->toBe('published')->remote_id->toBe('page1_42')->remote_url->toBe('https://www.facebook.com/page1_42');
});

test('a facebook post with one image goes to page photos', function () {
    Http::fake([g('page1/photos') => ['id' => '9', 'post_id' => 'page1_9']]);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Look', 'media' => ['https://cdn/a.jpg']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r['url'] === 'https://cdn/a.jpg' && $r['caption'] === 'Look');
    expect($post->targets()->sole()->remote_id)->toBe('page1_9');
});

test('a facebook post with several images uploads them unpublished and attaches them', function () {
    Http::fake([
        g('page1/photos') => Http::sequence()->push(['id' => 'p1'])->push(['id' => 'p2']),
        g('page1/feed') => ['id' => 'page1_7'],
    ]);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Two', 'media' => ['https://cdn/a.jpg', 'https://cdn/b.jpg']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === g('page1/photos') && $r['published'] === false);
    Http::assertSent(fn ($r) => $r->url() === g('page1/feed') && $r['attached_media'] === [['media_fbid' => 'p1'], ['media_fbid' => 'p2']]);
    expect($post->fresh()->status)->toBe('published');
});

test('an instagram image is created as a container and published', function () {
    Http::fake([
        g('ig1/media') => ['id' => 'c1'],
        g('ig1/media_publish') => ['id' => 'm1'],
        g('m1*') => ['permalink' => 'https://www.instagram.com/p/abc/'],
    ]);
    $post = queuedPost(['platform' => 'instagram', 'body' => 'Caption', 'media' => ['https://cdn/a.jpg']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === g('ig1/media') && $r['image_url'] === 'https://cdn/a.jpg' && $r['caption'] === 'Caption');
    Http::assertSent(fn ($r) => $r->url() === g('ig1/media_publish') && $r['creation_id'] === 'c1');
    expect($post->targets()->sole())->remote_id->toBe('m1')->remote_url->toBe('https://www.instagram.com/p/abc/');
});

test('several instagram images become a carousel', function () {
    Http::fake([
        g('ig1/media') => Http::sequence()->push(['id' => 'k1'])->push(['id' => 'k2'])->push(['id' => 'parent']),
        g('ig1/media_publish') => ['id' => 'm2'],
        g('m2*') => ['permalink' => 'https://www.instagram.com/p/xyz/'],
    ]);
    $post = queuedPost(['platform' => 'instagram', 'body' => 'Slides', 'media' => ['https://cdn/a.jpg', 'https://cdn/b.jpg']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === g('ig1/media') && ($r['is_carousel_item'] ?? false) === true && $r['image_url'] === 'https://cdn/b.jpg');
    Http::assertSent(fn ($r) => $r->url() === g('ig1/media') && ($r['media_type'] ?? null) === 'CAROUSEL' && $r['children'] === 'k1,k2' && $r['caption'] === 'Slides');
    Http::assertSent(fn ($r) => $r->url() === g('ig1/media_publish') && $r['creation_id'] === 'parent');
    expect($post->fresh()->status)->toBe('published');
});

test('an instagram video is published as a reel once processing finishes', function () {
    Sleep::fake();
    Http::fake([
        g('ig1/media') => ['id' => 'v1'],
        g('v1*') => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
        g('ig1/media_publish') => ['id' => 'm3'],
        g('m3*') => ['permalink' => 'https://www.instagram.com/reel/r/'],
    ]);
    $post = queuedPost(['platform' => 'instagram', 'media' => ['https://cdn/clip.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === g('ig1/media') && $r['media_type'] === 'REELS' && $r['video_url'] === 'https://cdn/clip.mp4');
    expect($post->fresh()->status)->toBe('published');
});

test('an instagram video that fails processing fails the post', function () {
    Sleep::fake();
    Http::fake([
        g('ig1/media') => ['id' => 'v1'],
        g('v1*') => ['status_code' => 'ERROR', 'status' => 'Error: bad codec'],
    ]);
    $post = queuedPost(['platform' => 'instagram', 'media' => ['https://cdn/clip.mov']]);

    PublishPost::dispatchSync($post->id);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'media_publish'));
    expect($post->fresh())->status->toBe('failed')->error->toContain('bad codec');
});

test('an instagram post without media fails clearly', function () {
    $post = queuedPost(['platform' => 'instagram', 'body' => 'Text only', 'media' => null]);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh())->status->toBe('failed')->error->toContain('Instagram needs at least one image or video');
});

test('a post with no connected account in its set fails', function () {
    $this->set->socialAccounts()->detach();
    $post = queuedPost(['platform' => 'facebook']);

    PublishPost::dispatchSync($post->id);

    expect($post->fresh())->status->toBe('failed')->error->toBe("No connected facebook account in social set {$this->set->name}");
});

test('a platform without a publisher still fails', function () {
    $post = queuedPost(['platform' => 'bluesky']);

    PublishPost::dispatchSync($post->id);

    expect($post->fresh())->status->toBe('failed')->error->toBe('No publisher for bluesky');
});

test('accounts outside the post set are not used', function () {
    $other = $this->team->socialSets()->create(['name' => 'Clients']);
    $post = Post::factory()->for($other)->create(['status' => 'queued', 'platform' => 'facebook']);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh()->status)->toBe('failed');
});

test('a partial failure publishes the post and names the failed account', function () {
    $second = SocialAccount::factory()->for($this->team)->create(['external_id' => 'page2', 'name' => 'Page Two']);
    $this->set->socialAccounts()->attach($second);
    Http::fake([
        g('page1/feed') => ['id' => 'page1_1'],
        g('page2/feed') => Http::response(['error' => ['message' => 'Permissions error']], 403),
    ]);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hi']);

    PublishPost::dispatchSync($post->id);

    expect($post->fresh())->status->toBe('published')->error->toBe('Failed on Page Two: Meta: Permissions error');
    expect($post->targets()->pluck('status', 'social_account_id')->all())->toBe([$this->page->id => 'published', $second->id => 'failed']);
});

test('when every account fails the post fails', function () {
    Http::fake([g('page1/feed') => Http::response(['error' => ['message' => 'Token expired']], 400)]);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hi']);

    PublishPost::dispatchSync($post->id);

    expect($post->fresh())->status->toBe('failed')->error->toBe('Failed on Page One: Meta: Token expired')->published_at->toBeNull();
});

test('a target already created remotely is never posted again', function () {
    Http::fake([g('page1/feed') => ['id' => 'page1_new']]);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hi']);
    $target = $post->targets()->create(['social_account_id' => $this->page->id, 'status' => 'published', 'remote_id' => 'page1_old']);

    PublishPost::dispatchSync($post->id);
    PublishPostTarget::dispatchSync($target->id);

    Http::assertNothingSent();
    expect($post->fresh()->status)->toBe('published')
        ->and($post->targets()->sole()->remote_id)->toBe('page1_old')
        ->and((new PublishPost($post->id))->tries)->toBe(1)
        ->and((new PublishPostTarget($target->id))->tries)->toBe(1);
});

test('a target another worker is already publishing is skipped and the post waits for it', function () {
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hi']);
    $target = $post->targets()->create(['social_account_id' => $this->page->id, 'status' => 'publishing']);

    PublishPostTarget::dispatchSync($target->id);
    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh()->status)->toBe('queued');
});

test('the post settles only when its last target finishes', function () {
    $second = SocialAccount::factory()->for($this->team)->create(['external_id' => 'page2', 'name' => 'Page Two']);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hi']);
    $first = $post->targets()->create(['social_account_id' => $this->page->id]);
    $last = $post->targets()->create(['social_account_id' => $second->id]);
    Http::fake([g('page1/feed') => ['id' => 'page1_1'], g('page2/feed') => ['id' => 'page2_1']]);

    PublishPostTarget::dispatchSync($first->id);
    expect($post->fresh()->status)->toBe('queued')
        ->and($first->fresh()->remote_id)->toBe('page1_1');

    PublishPostTarget::dispatchSync($last->id);
    expect($post->fresh()->status)->toBe('published');
});

test('a target whose job dies is failed without request details and settles the post', function () {
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hi']);
    $target = $post->targets()->create(['social_account_id' => $this->page->id, 'status' => 'publishing']);

    (new PublishPostTarget($target->id))->failed(new RuntimeException('timed out https://graph.facebook.com/?access_token=pt'));

    expect($target->fresh())->status->toBe('failed')->error->toBe('Publishing stopped unexpectedly.');
    expect($post->fresh()->status)->toBe('failed');
});

test('a connection failure stores an error without the token or url', function () {
    $this->page->update(['access_token' => 'secret-page-token']);
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://graph.facebook.com/v26.0/page1/feed?access_token=secret-page-token'));
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Hi']);

    PublishPost::dispatchSync($post->id);

    $error = $post->targets()->sole()->error;
    expect($error)->toBe('Meta: could not reach the Graph API.')
        ->and($post->fresh()->error)->not->toContain('secret-page-token')->not->toContain('graph.facebook.com');
});

test('the queue never hands a running publish to a second worker', function () {
    $retryAfter = config('queue.connections.database.retry_after');

    // The worker runs with --timeout=900 (deploy/docker/supervisord.conf).
    expect($retryAfter)->toBeGreaterThan(900)
        ->and($retryAfter)->toBeGreaterThan((new PublishPostTarget(1))->timeout);
});
