<?php

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\SocialSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = $this->user->currentTeam();
    $this->set = $this->team->socialSets()->sole();
    $this->otherSet = $this->team->socialSets()->create(['name' => 'Clients']);
    $this->token = $this->team->createToken('test')->plainTextToken;
    $this->setToken = $this->team->createToken('set', [$this->set->tokenAbility()])->plainTextToken;
});

function apiAs(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

test('api requests without a token get 401', function () {
    $this->getJson('/api/v1/posts')->assertUnauthorized();
    $this->postJson('/api/v1/posts', ['platform' => 'bluesky'])->assertUnauthorized();
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->getJson('/api/v1/social-sets')->assertUnauthorized();
});

test('a signed-in browser session does not authenticate the api', function () {
    $this->actingAs($this->user)->getJson('/api/v1/me')->assertUnauthorized();
});

test('me returns the team and a whole-team scope', function () {
    $this->getJson('/api/v1/me', apiAs($this->token))
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'team' => ['id' => $this->team->id, 'name' => $this->team->name],
                'scope' => ['type' => 'team'],
            ],
        ]);
});

test('me returns the social set of a set token', function () {
    $this->getJson('/api/v1/me', apiAs($this->setToken))
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'team' => ['id' => $this->team->id, 'name' => $this->team->name],
                'scope' => ['type' => 'social_set', 'social_set' => ['id' => $this->set->id, 'name' => 'Default', 'is_default' => true]],
            ],
        ]);
});

test('social sets lists every team set for a team token and only its own for a set token', function () {
    SocialSet::factory()->create();

    $this->getJson('/api/v1/social-sets', apiAs($this->token))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->set->id)
        ->assertJsonPath('data.1.id', $this->otherSet->id);

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/social-sets', apiAs($this->setToken))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->set->id);
});

test('a post without scheduled_at is saved as a draft', function () {
    $response = $this->postJson('/api/v1/posts', [
        'social_set_id' => $this->otherSet->id,
        'platform' => 'bluesky',
        'body' => 'Hello',
        'media' => ['https://example.com/a.png'],
    ], apiAs($this->token));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.social_set_id', $this->otherSet->id)
        ->assertJsonPath('data.platform', 'bluesky')
        ->assertJsonPath('data.media.0', 'https://example.com/a.png');

    $this->assertDatabaseHas('posts', [
        'id' => $response->json('data.id'),
        'team_id' => $this->team->id,
        'social_set_id' => $this->otherSet->id,
        'status' => 'draft',
    ]);
});

test('a post with a future scheduled_at is scheduled', function () {
    $this->postJson('/api/v1/posts', [
        'social_set_id' => $this->set->id,
        'platform' => 'x',
        'body' => 'Later',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ], apiAs($this->token))
        ->assertCreated()
        ->assertJsonPath('data.status', 'scheduled');
});

test('a team token must name a social set of its own team', function () {
    $this->postJson('/api/v1/posts', ['platform' => 'x'], apiAs($this->token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['social_set_id']);

    $this->postJson('/api/v1/posts', ['platform' => 'x', 'social_set_id' => SocialSet::factory()->create()->id], apiAs($this->token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['social_set_id']);
});

test('a set token posts to its own set and cannot pick another', function () {
    $this->postJson('/api/v1/posts', ['platform' => 'x'], apiAs($this->setToken))
        ->assertCreated()
        ->assertJsonPath('data.social_set_id', $this->set->id);

    $this->postJson('/api/v1/posts', ['platform' => 'x', 'social_set_id' => $this->otherSet->id], apiAs($this->setToken))
        ->assertForbidden();

    expect(Post::where('social_set_id', $this->otherSet->id)->exists())->toBeFalse();
});

test('posts can be listed, filtered by status, and shown', function () {
    $headers = apiAs($this->token);
    $draft = Post::factory()->for($this->set)->create(['status' => 'draft']);
    Post::factory()->for($this->otherSet)->create(['status' => 'published']);

    $this->getJson('/api/v1/posts', $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/posts?status=draft', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $draft->id);

    $this->getJson("/api/v1/posts/{$draft->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.id', $draft->id);
});

test('a set token only reaches posts in its set', function () {
    $headers = apiAs($this->setToken);
    $mine = Post::factory()->for($this->set)->create(['status' => 'draft']);
    $sibling = Post::factory()->for($this->otherSet)->create(['status' => 'draft']);

    $this->getJson('/api/v1/posts', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $this->getJson("/api/v1/posts/{$mine->id}", $headers)->assertOk();
    $this->getJson("/api/v1/posts/{$sibling->id}", $headers)->assertNotFound();
    $this->patchJson("/api/v1/posts/{$sibling->id}", ['body' => 'Mine now'], $headers)->assertNotFound();
    $this->deleteJson("/api/v1/posts/{$sibling->id}", [], $headers)->assertNotFound();

    $this->patchJson("/api/v1/posts/{$mine->id}", ['body' => 'Edited'], $headers)->assertOk();
    $this->deleteJson("/api/v1/posts/{$mine->id}", [], $headers)->assertNoContent();

    $this->assertModelExists($sibling);
});

test('a draft can be edited and its status follows scheduled_at', function () {
    $headers = apiAs($this->token);
    $post = Post::factory()->for($this->set)->create(['status' => 'draft']);

    $this->patchJson("/api/v1/posts/{$post->id}", [
        'body' => 'Edited',
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.body', 'Edited')
        ->assertJsonPath('data.status', 'scheduled');

    $this->patchJson("/api/v1/posts/{$post->id}", ['scheduled_at' => null], $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

test('a draft or scheduled post can be deleted', function () {
    $headers = apiAs($this->token);
    $post = Post::factory()->for($this->set)->create(['status' => 'scheduled']);

    $this->deleteJson("/api/v1/posts/{$post->id}", [], $headers)->assertNoContent();

    $this->assertModelMissing($post);
});

test('editing a published post or deleting a failed one returns 409', function () {
    $headers = apiAs($this->token);
    $post = Post::factory()->for($this->set)->create(['status' => 'published']);
    $failed = Post::factory()->for($this->set)->create(['status' => 'failed']);

    $this->patchJson("/api/v1/posts/{$post->id}", ['body' => 'Too late'], $headers)
        ->assertConflict();

    $this->deleteJson("/api/v1/posts/{$failed->id}", [], $headers)
        ->assertConflict();

    $this->assertDatabaseHas('posts', ['id' => $post->id, 'status' => 'published', 'body' => $post->body]);
    $this->assertDatabaseHas('posts', ['id' => $failed->id, 'status' => 'failed']);
});

test('other teams posts return 404', function () {
    $headers = apiAs($this->token);
    $other = Post::factory()->create(['status' => 'draft']);

    $this->getJson("/api/v1/posts/{$other->id}", $headers)->assertNotFound();
    $this->patchJson("/api/v1/posts/{$other->id}", ['body' => 'Mine now'], $headers)->assertNotFound();
    $this->deleteJson("/api/v1/posts/{$other->id}", [], $headers)->assertNotFound();

    $this->getJson('/api/v1/posts', $headers)->assertJsonCount(0, 'data');
});

test('invalid platform and past scheduled_at are rejected', function () {
    $headers = apiAs($this->token);

    $this->postJson('/api/v1/posts', ['social_set_id' => $this->set->id, 'platform' => 'myspace'], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platform']);

    $this->postJson('/api/v1/posts', [
        'social_set_id' => $this->set->id,
        'platform' => 'facebook',
        'scheduled_at' => now()->subHour()->toIso8601String(),
    ], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
});

test('posts show their targets without tokens', function () {
    $account = SocialAccount::factory()->for($this->team)->create(['name' => 'Page One']);
    $post = Post::factory()->for($this->set)->create(['status' => 'published', 'platform' => 'facebook']);
    $post->targets()->create(['social_account_id' => $account->id, 'status' => 'published', 'remote_id' => 'p_1', 'remote_url' => 'https://www.facebook.com/p_1']);

    $this->getJson('/api/v1/posts', apiAs($this->token))
        ->assertOk()
        ->assertJsonPath('data.0.targets.0.account', ['name' => 'Page One', 'provider' => 'facebook'])
        ->assertJsonPath('data.0.targets.0.status', 'published')
        ->assertJsonPath('data.0.targets.0.remote_url', 'https://www.facebook.com/p_1')
        ->assertDontSee($account->access_token);
});

test('social accounts are scoped to the token and never expose tokens', function () {
    $inDefault = SocialAccount::factory()->for($this->team)->create();
    $inBoth = SocialAccount::factory()->for($this->team)->instagram()->create();
    $unassigned = SocialAccount::factory()->for($this->team)->create();
    SocialAccount::factory()->create();
    $inBoth->socialSets()->syncWithoutDetaching($this->otherSet);
    $unassigned->socialSets()->detach();

    $this->getJson('/api/v1/social-accounts', apiAs($this->token))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.1.provider', 'instagram')
        ->assertJsonPath('data.1.social_set_ids', [$this->set->id, $this->otherSet->id])
        ->assertJsonPath('data.2.id', $unassigned->id)
        ->assertJsonMissingPath('data.0.access_token');

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/social-accounts', apiAs($this->setToken))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.social_set_ids', [$this->set->id]);
});

test('a team token creates a social set', function () {
    $response = $this->postJson('/api/v1/social-sets', ['name' => 'Launch'], apiAs($this->token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Launch');

    expect($this->team->socialSets()->find($response->json('data.id'))?->name)->toBe('Launch');
});

test('creating a social set validates the name', function () {
    $this->postJson('/api/v1/social-sets', ['name' => str_repeat('a', 256)], apiAs($this->token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('a set token cannot create social sets or change memberships', function () {
    $account = SocialAccount::factory()->for($this->team)->create();

    $this->postJson('/api/v1/social-sets', ['name' => 'Launch'], apiAs($this->setToken))->assertForbidden();
    $this->putJson("/api/v1/social-accounts/{$account->id}/social-sets", ['social_set_ids' => [$this->otherSet->id]], apiAs($this->setToken))->assertForbidden();

    expect($this->team->socialSets()->count())->toBe(2)
        ->and($account->socialSets()->pluck('social_sets.id')->all())->toBe([$this->set->id]);
});

test('a team token replaces an account\'s social sets, always keeping Default and links to deleted sets', function () {
    $account = SocialAccount::factory()->for($this->team)->create();
    $deleted = $this->team->socialSets()->create(['name' => 'Old']);
    $account->socialSets()->syncWithoutDetaching($deleted);
    $deleted->delete();

    $this->putJson("/api/v1/social-accounts/{$account->id}/social-sets", ['social_set_ids' => [$this->otherSet->id]], apiAs($this->token))
        ->assertOk()
        ->assertJsonPath('data.id', $account->id)
        ->assertJsonPath('data.social_set_ids', [$this->set->id, $this->otherSet->id]);

    expect($account->socialSets()->withTrashed()->pluck('social_sets.id')->sort()->values()->all())
        ->toBe([$this->set->id, $this->otherSet->id, $deleted->id]);

    $this->app['auth']->forgetGuards();

    $this->putJson("/api/v1/social-accounts/{$account->id}/social-sets", ['social_set_ids' => []], apiAs($this->token))
        ->assertOk()
        ->assertJsonPath('data.social_set_ids', [$this->set->id]);

    $this->getJson('/api/v1/social-sets', apiAs($this->token))->assertJsonPath('data.0.is_default', true);
});

test('membership rejects another team\'s account or set and deleted sets', function () {
    $account = SocialAccount::factory()->for($this->team)->create();
    $foreignAccount = SocialAccount::factory()->create();
    $foreignSet = SocialSet::factory()->create();
    $deleted = $this->team->socialSets()->create(['name' => 'Old']);
    $deleted->delete();
    $url = "/api/v1/social-accounts/{$account->id}/social-sets";

    $this->putJson("/api/v1/social-accounts/{$foreignAccount->id}/social-sets", ['social_set_ids' => [$this->set->id]], apiAs($this->token))->assertNotFound();
    $this->putJson($url, ['social_set_ids' => [$foreignSet->id]], apiAs($this->token))->assertUnprocessable()->assertJsonValidationErrors(['social_set_ids.0']);
    $this->putJson($url, ['social_set_ids' => [$deleted->id]], apiAs($this->token))->assertUnprocessable()->assertJsonValidationErrors(['social_set_ids.0']);
    $this->putJson($url, [], apiAs($this->token))->assertUnprocessable()->assertJsonValidationErrors(['social_set_ids']);

    expect($account->socialSets()->pluck('social_sets.id')->all())->toBe([$this->set->id])
        ->and($foreignAccount->socialSets()->count())->toBe(0);
});
