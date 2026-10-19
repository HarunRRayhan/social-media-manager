<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

// Not RefreshDatabase: SQLite rebuilds tables for these schema changes, which only works outside a transaction.
uses(DatabaseMigrations::class);

test('the migration moves existing users and posts into personal teams', function () {
    $this->artisan('migrate:rollback', ['--step' => 6])->assertSuccessful();

    $userId = DB::table('users')->insertGetId([
        'name' => 'Harun', 'username' => 'harun', 'email' => 'harun@example.com', 'password' => 'x',
    ]);
    DB::table('posts')->insert(['user_id' => $userId, 'platform' => 'x', 'status' => 'draft']);
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => 'App\Models\User', 'tokenable_id' => $userId, 'name' => 'old', 'token' => str_repeat('a', 64),
    ]);

    $this->artisan('migrate')->assertSuccessful();

    $user = User::findOrFail($userId);
    $team = $user->currentTeam();
    $post = Post::sole();

    expect($team->name)->toBe("Harun's Team")
        ->and($user->teamRole($team))->toBe('owner')
        ->and($post->team_id)->toBe($team->id)
        ->and($post->social_set_id)->toBe($team->socialSets()->where('name', 'Default')->sole()->id)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});
