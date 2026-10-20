<?php

use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Publishers\LinkedIn;
use App\Publishers\LinkedInPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const LI_PERSON = 'urn:li:person:abc';

beforeEach(function () {
    Http::preventStrayRequests();

    $set = User::factory()->create()->currentTeam()->socialSets()->sole();
    $account = SocialAccount::factory()->for($set->team)->create(['provider' => 'linkedin', 'external_id' => LI_PERSON, 'access_token' => 'lt']);
    $set->socialAccounts()->attach($account);
    $this->set = $set;
});

function linkedinPost(array $attributes): Post
{
    return Post::factory()->for(test()->set)->create(['status' => 'queued', 'platform' => 'linkedin', ...$attributes]);
}

function fakeLinkedInImages(int $count): void
{
    $init = Http::sequence();

    foreach (range(1, $count) as $i) {
        $init->push(['value' => ['uploadUrl' => "https://www.linkedin.com/dms-uploads/img{$i}/0", 'image' => "urn:li:image:img{$i}"]]);
    }

    Http::fake([
        'https://93.184.215.14/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => $init,
        'https://www.linkedin.com/dms-uploads/*' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:99']),
    ]);
}

test('a linkedin text post is created as the member with the version headers', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:42'])]);
    $post = linkedinPost(['body' => 'Hello (LinkedIn) #shipping']);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts'
        && $r->hasHeader('Authorization', 'Bearer lt')
        && $r->hasHeader('LinkedIn-Version', LinkedIn::VERSION)
        && $r->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && $r['author'] === LI_PERSON
        && $r['commentary'] === 'Hello \(LinkedIn\) #shipping'
        && $r['visibility'] === 'PUBLIC' && $r['lifecycleState'] === 'PUBLISHED'
        && ! isset($r['content']));
    expect($post->targets()->sole())->status->toBe('published')->remote_id->toBe('urn:li:share:42')
        ->remote_url->toBe('https://www.linkedin.com/feed/update/urn:li:share:42/');
});

test('one image is uploaded through the images api and attached as media', function () {
    fakeLinkedInImages(1);
    $post = linkedinPost(['body' => 'Look', 'media' => ['https://93.184.215.14/a.png']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'initializeUpload') && $r['initializeUploadRequest'] === ['owner' => LI_PERSON]);
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->url() === 'https://www.linkedin.com/dms-uploads/img1/0' && $r->body() === 'png-bytes' && $r->hasHeader('Authorization', 'Bearer lt'));
    Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts' && $r['content'] === ['media' => ['id' => 'urn:li:image:img1']]);
    expect($post->fresh()->status)->toBe('published');
});

test('several images become a multi-image post', function () {
    fakeLinkedInImages(2);
    $post = linkedinPost(['body' => 'Slides', 'media' => ['https://93.184.215.14/a.png', 'https://93.184.215.14/b.png']]);

    PublishPost::dispatchSync($post->id);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts'
        && $r['content'] === ['multiImage' => ['images' => [['id' => 'urn:li:image:img1'], ['id' => 'urn:li:image:img2']]]]);
    expect($post->fresh()->status)->toBe('published');
});

test('posts linkedin cannot take fail before calling it', function (array $attributes, string $error) {
    $post = linkedinPost($attributes);

    PublishPost::dispatchSync($post->id);

    Http::assertNothingSent();
    expect($post->fresh())->status->toBe('failed')->error->toContain($error);
})->with([
    'too long' => [['body' => str_repeat('a', 3001)], 'limited to 3000 characters'],
    'video' => [['body' => 'Watch', 'media' => ['https://cdn/clip.mp4']], 'video posts are not supported'],
    'pdf' => [['body' => 'Read', 'media' => ['https://cdn/deck.pdf']], 'document (PDF) posts are not supported'],
    'too many images' => [['body' => 'Many', 'media' => array_fill(0, 21, 'https://cdn/a.png')], 'at most 20 images'],
    'private image host' => [['body' => 'Look', 'media' => ['http://169.254.169.254/a.png']], 'public host'],
]);

test('an upload URL outside linkedin.com never receives the token', function (string $url) {
    Http::fake([
        'https://93.184.215.14/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => ['value' => ['uploadUrl' => $url, 'image' => 'urn:li:image:x']],
    ]);
    $post = linkedinPost(['body' => 'Look', 'media' => ['https://93.184.215.14/a.png']]);

    PublishPost::dispatchSync($post->id);

    Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    expect($post->fresh())->status->toBe('failed')->error->toContain('unexpected upload URL');
})->with([
    'other host' => ['https://evil.example/upload'],
    'lookalike host' => ['https://evillinkedin.com/upload'],
    'plain http' => ['http://www.linkedin.com/dms-uploads/x'],
]);

test('commentary escapes reserved characters but keeps hashtags', function () {
    expect(LinkedInPublisher::commentary('a_b *c* @d [e](f) {g}|<h>~ \\ # #tag'))
        ->toBe('a\_b \*c\* \@d \[e\]\(f\) \{g\}\|\<h\>\~ \\\\ \# #tag');
});
