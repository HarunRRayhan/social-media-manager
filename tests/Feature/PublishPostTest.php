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

test('a facebook video is published as a page reel', function () {
    Sleep::fake();
    Http::fake([
        g('page1/video_reels') => Http::sequence()->push(['video_id' => 'r1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v26.0/r1'])->push(['success' => true]),
        'https://rupload.facebook.com/video-upload/v26.0/r1' => ['success' => true],
        g('r1*') => Http::sequence()
            ->push(['status' => ['video_status' => 'uploading', 'uploading_phase' => ['status' => 'in_progress']]])
            ->push(['status' => ['video_status' => 'processing', 'uploading_phase' => ['status' => 'complete']]]),
    ]);
    $post = queuedPost(['platform' => 'facebook', 'body' => 'Watch', 'media' => ['https://cdn/clip.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === g('page1/video_reels') && $r['upload_phase'] === 'start');
    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://rupload.facebook.com/')
        && $r->hasHeader('Authorization', 'OAuth pt') && $r->hasHeader('file_url', 'https://cdn/clip.mp4') && ! str_contains($r->url(), 'pt'));
    Http::assertSent(fn ($r) => $r->url() === g('page1/video_reels') && $r['upload_phase'] === 'finish'
        && $r['video_id'] === 'r1' && $r['video_state'] === 'PUBLISHED' && $r['description'] === 'Watch');
    expect($post->fresh()->status)->toBe('published')
        ->and($post->targets()->sole()->remote_id)->toBe('r1');
});

test('a facebook reel whose upload fails is never published', function () {
    Sleep::fake();
    Http::fake([
        g('page1/video_reels') => ['video_id' => 'r1'],
        'https://rupload.facebook.com/*' => ['success' => true],
        g('r1*') => ['status' => ['video_status' => 'upload_failed']],
    ]);
    $post = queuedPost(['platform' => 'facebook', 'media' => ['https://cdn/clip.mov']]);

    PublishPost::dispatchSync($post->id);

    Http::assertNotSent(fn ($r) => ($r['upload_phase'] ?? null) === 'finish');
    expect($post->fresh())->status->toBe('failed')->error->toContain('upload_failed');
});

test('a facebook post mixing a video with other media fails clearly', function () {
    $post = queuedPost(['platform' => 'facebook', 'media' => ['https://cdn/a.jpg', 'https://cdn/clip.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh())->status->toBe('failed')->error->toContain('post the video on its own as a Reel');
});

test('an instagram motion carousel waits for every video child, then for the parent, before publishing', function () {
    Sleep::fake();
    Http::fake([
        g('ig1/media') => Http::sequence()->push(['id' => 'k1'])->push(['id' => 'k2'])->push(['id' => 'k3'])->push(['id' => 'parent']),
        g('k1*') => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
        g('k3*') => ['status_code' => 'FINISHED'],
        g('parent*') => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
        g('ig1/media_publish') => ['id' => 'm4'],
        g('m4*') => ['permalink' => 'https://www.instagram.com/p/motion/'],
    ]);
    $post = queuedPost(['platform' => 'instagram', 'body' => 'Moving', 'media' => ['https://cdn/a.mp4', 'https://cdn/b.jpg', 'https://cdn/c.mov']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === g('ig1/media') && ($r['media_type'] ?? null) === 'VIDEO' && $r['video_url'] === 'https://cdn/a.mp4' && $r['is_carousel_item'] === true);
    Http::assertSent(fn ($r) => $r->url() === g('ig1/media') && ($r['image_url'] ?? null) === 'https://cdn/b.jpg' && $r['is_carousel_item'] === true);
    Http::assertNotSent(fn ($r) => str_starts_with($r->url(), g('k2')));

    $requests = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
    $lastPoll = $requests->filter(fn ($r) => str_starts_with($r->url(), g('k')))->keys()->last();
    $parent = $requests->search(fn ($r) => ($r['media_type'] ?? null) === 'CAROUSEL');
    expect($parent)->toBeGreaterThan($lastPoll)
        ->and($requests[$parent]['children'])->toBe('k1,k2,k3')
        ->and($post->fresh()->status)->toBe('published');

    $parentPolls = $requests->filter(fn ($r) => str_starts_with($r->url(), g('parent')))->keys();
    $publish = $requests->search(fn ($r) => str_contains($r->url(), 'media_publish'));
    expect($parentPolls)->toHaveCount(2)->and($publish)->toBeGreaterThan($parentPolls->last());
});

test('an instagram motion carousel with a failed video child is never published', function () {
    Sleep::fake();
    Http::fake([
        g('ig1/media') => Http::sequence()->push(['id' => 'k1'])->push(['id' => 'k2']),
        g('k1*') => ['status_code' => 'FINISHED'],
        g('k2*') => ['status_code' => 'ERROR', 'status' => 'Error: too long'],
    ]);
    $post = queuedPost(['platform' => 'instagram', 'media' => ['https://cdn/a.mp4', 'https://cdn/b.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertNotSent(fn ($r) => ($r['media_type'] ?? null) === 'CAROUSEL' || str_contains($r->url(), 'media_publish'));
    expect($post->fresh())->status->toBe('failed')->error->toContain('too long');
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
    $post = queuedPost(['platform' => 'x']);

    PublishPost::dispatchSync($post->id);

    expect($post->fresh())->status->toBe('failed')->error->toBe('No publisher for x');
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

function bluesky(string $method): string
{
    return "https://bsky.social/xrpc/{$method}";
}

test('a bluesky text post signs in and creates a record with a byte-offset link facet', function () {
    $sky = SocialAccount::factory()->for($this->team)->create(['provider' => 'bluesky', 'external_id' => 'did:plc:abc', 'access_token' => 'app-pass']);
    $this->set->socialAccounts()->attach($sky);
    Http::fake([
        bluesky('com.atproto.server.createSession') => ['did' => 'did:plc:abc', 'handle' => 'h.bsky.social', 'accessJwt' => 'jwt'],
        bluesky('com.atproto.repo.createRecord') => ['uri' => 'at://did:plc:abc/app.bsky.feed.post/3kxyz', 'cid' => 'c'],
    ]);
    $post = queuedPost(['platform' => 'bluesky', 'body' => 'নতুন see https://harun.dev/x.']);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === bluesky('com.atproto.server.createSession') && $r['identifier'] === 'did:plc:abc' && $r['password'] === 'app-pass');
    Http::assertSent(fn ($r) => $r->url() === bluesky('com.atproto.repo.createRecord') && $r->hasHeader('Authorization', 'Bearer jwt')
        && $r['repo'] === 'did:plc:abc' && $r['record']['text'] === 'নতুন see https://harun.dev/x.'
        && $r['record']['facets'] === [['index' => ['byteStart' => 17, 'byteEnd' => 36], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://harun.dev/x']]]]
        && ! isset($r['record']['embed']));
    expect($post->targets()->sole())->status->toBe('published')->remote_id->toBe('at://did:plc:abc/app.bsky.feed.post/3kxyz')
        ->remote_url->toBe('https://bsky.app/profile/did:plc:abc/post/3kxyz');
});

test('a bluesky image is uploaded as a blob and embedded', function () {
    $sky = SocialAccount::factory()->for($this->team)->create(['provider' => 'bluesky', 'external_id' => 'did:plc:abc']);
    $this->set->socialAccounts()->attach($sky);
    $blob = ['$type' => 'blob', 'ref' => ['$link' => 'bafy'], 'mimeType' => 'image/png', 'size' => 3];
    Http::fake([
        'https://93.184.215.14/a.png' => Http::response('png', 200, ['Content-Type' => 'image/png']),
        bluesky('com.atproto.server.createSession') => ['did' => 'did:plc:abc', 'handle' => 'h', 'accessJwt' => 'jwt'],
        bluesky('com.atproto.repo.uploadBlob') => ['blob' => $blob],
        bluesky('com.atproto.repo.createRecord') => ['uri' => 'at://did:plc:abc/app.bsky.feed.post/3k1'],
    ]);
    $post = queuedPost(['platform' => 'bluesky', 'body' => 'Look', 'media' => ['https://93.184.215.14/a.png']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === bluesky('com.atproto.repo.uploadBlob') && $r->body() === 'png' && $r->hasHeader('Content-Type', 'image/png'));
    Http::assertSent(fn ($r) => $r->url() === bluesky('com.atproto.repo.createRecord')
        && $r['record']['embed'] === ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => '', 'image' => $blob]]]);
    expect($post->fresh()->status)->toBe('published');
});

test('a bluesky post over 300 characters or with a video fails before signing in', function (array $attributes, string $error) {
    $sky = SocialAccount::factory()->for($this->team)->create(['provider' => 'bluesky', 'external_id' => 'did:plc:abc']);
    $this->set->socialAccounts()->attach($sky);
    $post = queuedPost(['platform' => 'bluesky', ...$attributes]);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh())->status->toBe('failed')->error->toContain($error);
})->with([
    'too long' => [['body' => str_repeat('অ', 301)], 'limited to 300 characters'],
    'video' => [['body' => 'Watch', 'media' => ['https://cdn/clip.mp4']], 'video posts are not supported'],
]);

test('a bluesky image from a private host, not an image, or over 1 MB fails without uploading', function (string $url, ?string $body, string $error) {
    $sky = SocialAccount::factory()->for($this->team)->create(['provider' => 'bluesky', 'external_id' => 'did:plc:abc']);
    $this->set->socialAccounts()->attach($sky);
    Http::fake([
        'https://93.184.215.14/*' => Http::response($body, 200, ['Content-Type' => str_ends_with($url, '.html') ? 'text/html' : 'image/png']),
        bluesky('com.atproto.server.createSession') => ['did' => 'did:plc:abc', 'handle' => 'h', 'accessJwt' => 'jwt'],
    ]);
    $post = queuedPost(['platform' => 'bluesky', 'body' => 'Look', 'media' => [$url]]);

    PublishPost::dispatchSync($post->id);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'uploadBlob') || str_contains($r->url(), 'createRecord'));
    expect($post->fresh())->status->toBe('failed')->error->toContain($error);
})->with([
    'metadata address' => ['http://169.254.169.254/latest.png', null, 'public host'],
    'loopback' => ['http://127.0.0.1/a.png', null, 'public host'],
    'carrier-grade NAT' => ['http://100.64.0.1/a.png', null, 'public host'],
    'not an image' => ['https://93.184.215.14/page.html', '<html>', 'not an image'],
    'too big' => ['https://93.184.215.14/big.png', str_repeat('x', 1_000_001), '1 MB or smaller'],
]);
