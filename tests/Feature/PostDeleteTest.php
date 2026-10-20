<?php

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v26.0']);

    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
    $this->set = $this->team->socialSets()->sole();
    $this->token = $this->team->createToken('test')->plainTextToken;
});

/** A published post on one account of the given provider, with that account's remote id. */
function publishedOn(string $provider, string $remoteId, array $account = []): Post
{
    $model = SocialAccount::factory()->for(test()->team)->create(['provider' => $provider, 'name' => 'Acct', ...$account]);
    $post = Post::factory()->for(test()->set)->create(['platform' => $provider, 'status' => 'published', 'published_at' => now()]);
    $post->targets()->create(['social_account_id' => $model->id, 'status' => 'published', 'remote_id' => $remoteId, 'published_at' => now()]);

    return $post;
}

function deleteViaApi(Post $post)
{
    return test()->deleteJson("/api/v1/posts/{$post->id}", [], ['Authorization' => 'Bearer '.test()->token]);
}

function expectDeleted(Post $post): void
{
    expect($post->fresh()->status)->toBe('deleted')
        ->and($post->targets()->sole()->status)->toBe('deleted');
}

test('a facebook page post is deleted with the page token', function () {
    Http::fake(['https://graph.facebook.com/v26.0/page1_42' => ['success' => true]]);
    $post = publishedOn('facebook', 'page1_42', ['access_token' => 'pt']);

    deleteViaApi($post)->assertOk()->assertJsonPath('data.status', 'deleted')->assertJsonPath('data.targets.0.status', 'deleted');

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'https://graph.facebook.com/v26.0/page1_42' && $r->hasHeader('Authorization', 'Bearer pt'));
    expectDeleted($post);
});

test('an instagram post is deleted by its media id', function () {
    Http::fake(['https://graph.facebook.com/v26.0/m1' => ['success' => true, 'deleted_id' => 'm1']]);
    $post = publishedOn('instagram', 'm1');

    deleteViaApi($post)->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'https://graph.facebook.com/v26.0/m1');
    expectDeleted($post);
});

test('a threads post is deleted on graph.threads.com', function () {
    Http::fake(['https://graph.threads.com/v1.0/t1' => ['success' => true, 'deleted_id' => 't1']]);
    $post = publishedOn('threads', 't1', ['access_token' => 'tt']);

    deleteViaApi($post)->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'https://graph.threads.com/v1.0/t1' && $r->hasHeader('Authorization', 'Bearer tt'));
    expectDeleted($post);
});

test('a linkedin post is deleted by its encoded urn', function () {
    Http::fake(['https://api.linkedin.com/rest/posts/*' => Http::response(null, 204)]);
    $post = publishedOn('linkedin', 'urn:li:share:123', ['external_id' => 'urn:li:person:abc']);

    deleteViaApi($post)->assertOk();

    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && $r->url() === 'https://api.linkedin.com/rest/posts/urn%3Ali%3Ashare%3A123'
        && $r->hasHeader('X-RestLi-Method', 'DELETE')
        && $r->hasHeader('LinkedIn-Version'));
    expectDeleted($post);
});

test('a bluesky post is deleted by its record key', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => ['did' => 'did:plc:me', 'handle' => 'me.bsky.social', 'accessJwt' => 'jwt'],
        'https://bsky.social/xrpc/com.atproto.repo.deleteRecord' => ['commit' => ['cid' => 'c', 'rev' => 'r']],
    ]);
    $post = publishedOn('bluesky', 'at://did:plc:me/app.bsky.feed.post/3abc', ['external_id' => 'did:plc:me', 'access_token' => 'app-pass']);

    deleteViaApi($post)->assertOk();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'deleteRecord')
        && $r->hasHeader('Authorization', 'Bearer jwt')
        && $r['repo'] === 'did:plc:me' && $r['collection'] === 'app.bsky.feed.post' && $r['rkey'] === '3abc');
    expectDeleted($post);
});

test('a missing delete permission asks to reconnect the account and keeps the post published', function () {
    Http::fake(['https://graph.threads.com/v1.0/t1' => Http::response(['error' => ['message' => 'Application does not have permission for this action', 'code' => 10]], 403)]);
    $post = publishedOn('threads', 't1');

    deleteViaApi($post)->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'reconnect this account under Connections'));

    expect($post->fresh()->status)->toBe('published')
        ->and($post->targets()->sole()->status)->toBe('published');
});

test('linkedin 403 also asks to reconnect', function () {
    Http::fake(['https://api.linkedin.com/rest/posts/*' => Http::response(['message' => 'Not enough permissions'], 403)]);
    $post = publishedOn('linkedin', 'urn:li:share:9');

    deleteViaApi($post)->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'reconnect'));
});

test('another platform error says to delete it in the app', function () {
    Http::fake(['https://graph.facebook.com/v26.0/m1' => Http::response(['error' => ['message' => 'The media type is not supported for this endpoint.', 'code' => 100]], 400)]);
    $post = publishedOn('instagram', 'm1');

    deleteViaApi($post)->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'delete it in the Instagram app') && ! str_contains($m, 'reconnect'));
    expect($post->fresh()->status)->toBe('published');
});

test('a retry after a partial failure only deletes what is left', function () {
    $post = publishedOn('facebook', 'page1_1');
    $second = SocialAccount::factory()->for($this->team)->create(['provider' => 'facebook']);
    $post->targets()->create(['social_account_id' => $second->id, 'status' => 'deleted', 'remote_id' => 'page2_1']);
    Http::fake(['https://graph.facebook.com/v26.0/page1_1' => ['success' => true]]);

    deleteViaApi($post)->assertOk();

    Http::assertSentCount(1);
    expect($post->fresh()->status)->toBe('deleted');
});

test('drafts are still removed outright and queued posts cannot be deleted', function () {
    $draft = Post::factory()->for($this->set)->create(['status' => 'draft']);
    $queued = Post::factory()->for($this->set)->create(['status' => 'queued']);

    deleteViaApi($draft)->assertNoContent();
    deleteViaApi($queued)->assertConflict();

    $this->assertModelMissing($draft);
    $this->assertModelExists($queued);
});

test('the calendar delete action takes the post down and flashes the result', function () {
    Http::fake(['https://graph.facebook.com/v26.0/page1_42' => ['success' => true]]);
    $post = publishedOn('facebook', 'page1_42');

    $this->actingAs($this->owner)
        ->from(route('calendar.index'))
        ->delete(route('posts.destroy', $post))
        ->assertRedirect(route('calendar.index'))
        ->assertInertiaFlash('toast.type', 'success');

    expectDeleted($post);
});

test('the calendar delete action reports a reconnect error', function () {
    Http::fake(['https://graph.facebook.com/v26.0/m1' => Http::response(['error' => ['message' => 'Permissions error', 'code' => 200]], 403)]);
    $post = publishedOn('instagram', 'm1');

    $this->actingAs($this->owner)
        ->delete(route('posts.destroy', $post))
        ->assertInertiaFlash('toast.type', 'error');

    expect($post->fresh()->status)->toBe('published');
});

test('members cannot delete posts and other teams posts are not found', function () {
    $post = publishedOn('facebook', 'page1_42');
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'member']);
    $member->forceFill(['current_team_id' => $this->team->id])->save();

    $this->actingAs($member)->delete(route('posts.destroy', $post))->assertForbidden();
    $this->actingAs(User::factory()->create())->delete(route('posts.destroy', $post))->assertNotFound();

    Http::assertNothingSent();
});

test('an expired meta token or a revoked bluesky app password asks to reconnect', function (string $provider, string $remoteId, string $url, array $body, int $status) {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401),
        $url => Http::response($body, $status),
    ]);
    $post = publishedOn($provider, $remoteId);

    deleteViaApi($post)->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'reconnect this account'));
})->with([
    'facebook 190' => ['facebook', 'p_1', 'https://graph.facebook.com/v26.0/p_1', ['error' => ['message' => 'Session has expired', 'code' => 190]], 400],
    'bluesky 401' => ['bluesky', 'at://did:plc:me/app.bsky.feed.post/3x', 'https://bsky.social/xrpc/com.atproto.repo.deleteRecord', [], 200],
]);

test('a post on a removed account says to delete it in the app', function () {
    $post = publishedOn('facebook', 'p_1');
    $post->targets()->sole()->socialAccount->delete();

    deleteViaApi($post)->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'delete it in the Facebook app'));
    Http::assertNothingSent();
});

test('a social set token cannot delete another sets published post', function () {
    $other = $this->team->socialSets()->create(['name' => 'Clients']);
    $post = publishedOn('facebook', 'p_1');
    $post->update(['social_set_id' => $other->id]);
    $setToken = $this->team->createToken('set', [$this->set->tokenAbility()])->plainTextToken;

    $this->deleteJson("/api/v1/posts/{$post->id}", [], ['Authorization' => 'Bearer '.$setToken])->assertNotFound();
    Http::assertNothingSent();
    expect($post->fresh()->status)->toBe('published');
});
