<?php

use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Publishers\XPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.x' => ['client_id' => 'x-app', 'client_secret' => 'x-secret']]);

    $set = User::factory()->create()->currentTeam()->socialSets()->sole();
    $this->account = SocialAccount::factory()->for($set->team)->create([
        'provider' => 'x', 'external_id' => '123', 'username' => 'harun', 'access_token' => 'xt',
        'token_expires_at' => now()->addHour(), 'meta' => ['refresh_token' => Crypt::encryptString('r1')],
    ]);
    $set->socialAccounts()->syncWithoutDetaching($this->account);
    $this->set = $set;
});

function xPost(array $attributes): Post
{
    return Post::factory()->for(test()->set)->create(['status' => 'queued', 'platform' => 'x', ...$attributes]);
}

/** Every X upload endpoint faked for one media id, plus the post create. */
function fakeXUpload(string $mediaId, array $finalize = [], array $status = []): void
{
    Http::fake([
        'https://api.x.com/2/media/upload/initialize' => ['data' => ['id' => $mediaId, 'media_key' => "3_{$mediaId}"]],
        "https://api.x.com/2/media/upload/{$mediaId}/append" => Http::response('', 204),
        "https://api.x.com/2/media/upload/{$mediaId}/finalize" => ['data' => ['id' => $mediaId, ...$finalize]],
        'https://api.x.com/2/media/upload?*' => $status ?: ['data' => ['id' => $mediaId, 'processing_info' => ['state' => 'succeeded']]],
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '9001', 'text' => 'x']], 201),
    ]);
}

test('an x text post is created with the bearer token and linked by username', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '42', 'text' => 'Hello X']], 201)]);
    $post = xPost(['body' => 'Hello X']);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r->hasHeader('Authorization', 'Bearer xt')
        && $r['text'] === 'Hello X' && ! isset($r['media']));
    expect($post->targets()->sole())->status->toBe('published')->remote_id->toBe('42')->remote_url->toBe('https://x.com/harun/status/42');
});

test('an expiring token is refreshed before posting and the rotated tokens are stored', function () {
    $this->account->update(['token_expires_at' => now()->addMinute()]);
    Http::fake([
        'https://api.x.com/2/oauth2/token' => ['access_token' => 'xt2', 'refresh_token' => 'r2', 'expires_in' => 7200],
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '43']], 201),
    ]);
    $post = xPost(['body' => 'Hi']);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/oauth2/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1');
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r->hasHeader('Authorization', 'Bearer xt2'));
    expect($post->fresh()->status)->toBe('published')
        ->and($this->account->fresh()->access_token)->toBe('xt2')
        ->and(Crypt::decryptString($this->account->fresh()->meta['refresh_token']))->toBe('r2');
});

test('an account flagged to reconnect fails without calling x', function () {
    $this->account->update(['token_expires_at' => now()->subHour(), 'meta' => ['reconnect' => true]]);
    $post = xPost(['body' => 'Hi']);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh())->status->toBe('failed')->error->toContain('connected again');
});

test('an image is uploaded in one chunk as tweet_image and attached', function () {
    fakeXUpload('555');
    Http::fake(['https://93.184.215.14/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png'])]);
    $post = xPost(['body' => 'Look', 'media' => ['https://93.184.215.14/a.png']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/media/upload/initialize' && $r->hasHeader('Authorization', 'Bearer xt')
        && $r['media_type'] === 'image/png' && $r['total_bytes'] === 9 && $r['media_category'] === 'tweet_image');
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/media/upload/555/append' && $r->isMultipart()
        && collect($r->data())->contains(fn ($part) => $part['name'] === 'segment_index' && $part['contents'] === '0')
        && collect($r->data())->contains(fn ($part) => $part['name'] === 'media' && $part['contents'] === 'png-bytes'));
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/media/upload/555/finalize');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'command=STATUS'));
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['media'] === ['media_ids' => ['555']]);
    expect($post->fresh()->status)->toBe('published');
});

test('a video is chunked, finalized, and polled until succeeded', function () {
    Sleep::fake();
    $bytes = str_repeat('v', 4_000_010);
    Http::fake([
        'https://93.184.215.14/*' => Http::response($bytes, 200, ['Content-Type' => 'video/mp4']),
        'https://api.x.com/2/media/upload/initialize' => ['data' => ['id' => '777']],
        'https://api.x.com/2/media/upload/777/append' => Http::response('', 204),
        'https://api.x.com/2/media/upload/777/finalize' => ['data' => ['id' => '777', 'processing_info' => ['state' => 'pending', 'check_after_secs' => 2]]],
        'https://api.x.com/2/media/upload?*' => Http::sequence()
            ->push(['data' => ['id' => '777', 'processing_info' => ['state' => 'in_progress', 'check_after_secs' => 3]]])
            ->push(['data' => ['id' => '777', 'processing_info' => ['state' => 'succeeded']]]),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '9001']], 201),
    ]);
    $post = xPost(['body' => 'Watch', 'media' => ['https://93.184.215.14/clip.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/media/upload/initialize' && $r['media_type'] === 'video/mp4'
        && $r['total_bytes'] === 4_000_010 && $r['media_category'] === 'tweet_video');
    $appends = collect(Http::recorded(fn ($r) => $r->url() === 'https://api.x.com/2/media/upload/777/append'))
        ->map(fn ($pair) => collect($pair[0]->data())->pluck('contents', 'name'))->values();
    expect($appends)->toHaveCount(2)
        ->and($appends[0]['segment_index'])->toBe('0')->and(strlen($appends[0]['media']))->toBe(4_000_000)
        ->and($appends[1]['segment_index'])->toBe('1')->and(strlen($appends[1]['media']))->toBe(10);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/media/upload?command=STATUS&media_id=777');
    Http::assertSentCount(8);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(3)->seconds()]);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['media'] === ['media_ids' => ['777']]);
    expect($post->fresh()->status)->toBe('published');
});

test('a video x fails to process marks the post failed with its reason', function () {
    Http::fake([
        'https://93.184.215.14/*' => Http::response('vid', 200, ['Content-Type' => 'video/mp4']),
        'https://api.x.com/2/media/upload/initialize' => ['data' => ['id' => '1']],
        'https://api.x.com/2/media/upload/1/append' => Http::response('', 204),
        'https://api.x.com/2/media/upload/1/finalize' => ['data' => ['id' => '1', 'processing_info' => ['state' => 'failed', 'error' => ['message' => 'Unsupported codec']]]],
    ]);
    $post = xPost(['body' => 'Watch', 'media' => ['https://93.184.215.14/clip.mp4']]);

    PublishPost::dispatchSync($post->id);

    Http::assertNotSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets');
    expect($post->fresh())->status->toBe('failed')->error->toContain('Unsupported codec');
});

test('posts x cannot take fail before calling it', function (array $attributes, string $error) {
    $post = xPost($attributes);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh())->status->toBe('failed')->error->toContain($error);
})->with([
    'too long' => [['body' => str_repeat('a', 281)], 'limited to 280 characters; this one has 281'],
    'too many images' => [['body' => 'Many', 'media' => array_fill(0, 5, 'https://cdn/a.png')], 'at most 4 images'],
    'video plus image' => [['body' => 'Mix', 'media' => ['https://cdn/a.mp4', 'https://cdn/a.png']], 'not both'],
    'private image host' => [['body' => 'Look', 'media' => ['http://169.254.169.254/a.png']], 'public host'],
]);

test('a 429 stores the rate limit error with its reset time and is not retried', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['title' => 'Too Many Requests', 'detail' => 'Too Many Requests'], 429, ['x-rate-limit-reset' => (string) mktime(13, 30, 0, 1, 1, 2030)])]);
    $post = xPost(['body' => 'Busy']);

    PublishPost::dispatchSync($post->id);

    Http::assertSentCount(1);
    expect($post->fresh())->status->toBe('failed')->error->toContain('rate limit')->error->toContain('not retried')
        ->error->toContain(gmdate('H:i', mktime(13, 30, 0, 1, 1, 2030)));
});

test('urls weigh 23 characters whatever their length', function () {
    expect(XPublisher::length('see https://example.com/a/very/long/path/that/goes/on/and/on/for/ever ok'))->toBe(4 + 23 + 3)
        ->and(XPublisher::length('plain'))->toBe(5);
});

test('a post without a stored username links through x.com/i/web', function () {
    $this->account->update(['username' => null]);

    expect(app(XPublisher::class)->url('7', $this->account->fresh()))->toBe('https://x.com/i/web/status/7');
});
