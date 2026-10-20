<?php

use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();

    $set = User::factory()->create()->currentTeam()->socialSets()->sole();
    $account = SocialAccount::factory()->for($set->team)->create(['provider' => 'threads', 'external_id' => 'u1', 'access_token' => 'tt']);
    $set->socialAccounts()->attach($account);
    $this->set = $set;
});

function th(string $path): string
{
    return "https://graph.threads.com/v1.0/{$path}";
}

function threadsPost(array $attributes): Post
{
    return Post::factory()->for(test()->set)->create(['status' => 'queued', 'platform' => 'threads', ...$attributes]);
}

test('a threads text post is created as a text container and published', function () {
    Http::fake([
        th('u1/threads') => ['id' => 'c1'],
        th('c1*') => Http::sequence()->push(['status' => 'IN_PROGRESS'])->push(['status' => 'FINISHED']),
        th('u1/threads_publish') => ['id' => 't1'],
        th('t1*') => ['permalink' => 'https://www.threads.com/@harun/post/abc'],
    ]);
    $post = threadsPost(['body' => 'Hello Threads']);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === th('u1/threads') && $r['media_type'] === 'TEXT' && $r['text'] === 'Hello Threads' && $r->hasHeader('Authorization', 'Bearer tt'));
    Http::assertSent(fn ($r) => $r->url() === th('u1/threads_publish') && $r['creation_id'] === 'c1');
    Http::assertSentCount(5); // create, two status checks, publish, permalink
    expect($post->targets()->sole())->status->toBe('published')->remote_id->toBe('t1')->remote_url->toBe('https://www.threads.com/@harun/post/abc');
});

test('several items become a carousel once every child is processed', function () {
    Http::fake([
        th('u1/threads') => Http::sequence()->push(['id' => 'k1'])->push(['id' => 'k2'])->push(['id' => 'parent']),
        th('k1*') => ['status' => 'FINISHED'],
        th('k2*') => Http::sequence()->push(['status' => 'IN_PROGRESS'])->push(['status' => 'FINISHED']),
        th('parent*') => ['status' => 'FINISHED'],
        th('u1/threads_publish') => ['id' => 't2'],
        th('t2*') => ['permalink' => 'https://www.threads.com/@harun/post/xyz'],
    ]);
    $post = threadsPost(['body' => 'Slides', 'media' => ['https://cdn/a.jpg', 'https://cdn/clip.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === th('u1/threads') && ($r['is_carousel_item'] ?? false) === true && $r['media_type'] === 'IMAGE' && $r['image_url'] === 'https://cdn/a.jpg');
    Http::assertSent(fn ($r) => $r->url() === th('u1/threads') && ($r['is_carousel_item'] ?? false) === true && $r['media_type'] === 'VIDEO' && $r['video_url'] === 'https://cdn/clip.mp4');
    Http::assertSent(fn ($r) => $r->url() === th('u1/threads') && $r['media_type'] === 'CAROUSEL' && $r['children'] === 'k1,k2' && $r['text'] === 'Slides');
    Http::assertSent(fn ($r) => $r->url() === th('u1/threads_publish') && $r['creation_id'] === 'parent');
    expect($post->fresh()->status)->toBe('published');
});

test('a single video that fails processing is never published', function () {
    Http::fake([
        th('u1/threads') => ['id' => 'v1'],
        th('v1*') => ['status' => 'ERROR', 'error_message' => 'FAILED_DOWNLOADING_VIDEO'],
    ]);
    $post = threadsPost(['media' => ['https://cdn/clip.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'threads_publish'));
    expect($post->fresh())->status->toBe('failed')->error->toContain('Threads could not process the media: FAILED_DOWNLOADING_VIDEO');
});

test('text over 500 characters fails clearly without calling threads', function () {
    $post = threadsPost(['body' => str_repeat('a', 501)]);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh())->status->toBe('failed')->error->toContain('Threads allows at most 500 characters.');
});
