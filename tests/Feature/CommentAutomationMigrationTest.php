<?php

use App\Models\CommentAutomation;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Not RefreshDatabase: SQLite rebuilds tables for these schema changes, which only works outside a transaction.
uses(DatabaseMigrations::class);

test('the migration moves each automation account and post into the new shape and keeps its runs', function () {
    $team = User::factory()->create()->currentTeam();
    $page = SocialAccount::factory()->for($team)->create();
    $ig = SocialAccount::factory()->for($team)->instagram()->create();

    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

    DB::table('comment_automations')->insert([
        ['id' => 1, 'team_id' => $team->id, 'social_account_id' => $page->id, 'name' => 'One', 'post_remote_id' => 'page1_5', 'keywords' => '["a"]', 'dm_message' => 'x', 'active' => true],
        ['id' => 2, 'team_id' => $team->id, 'social_account_id' => $ig->id, 'name' => 'All', 'post_remote_id' => null, 'keywords' => '["b"]', 'dm_message' => 'y', 'active' => true],
    ]);
    DB::table('comment_automation_runs')->insert(['comment_automation_id' => 1, 'comment_id' => 'c1', 'status' => 'sent']);

    $this->artisan('migrate')->assertSuccessful();

    $one = CommentAutomation::findOrFail(1);
    $all = CommentAutomation::findOrFail(2);
    expect($one->post_remote_ids)->toBe(['page1_5'])
        ->and($one->accounts->pluck('id')->all())->toBe([$page->id])
        ->and($one->runs()->count())->toBe(1)
        ->and($all->post_remote_ids)->toBeNull()
        ->and($all->accounts->pluck('id')->all())->toBe([$ig->id])
        ->and(Schema::hasColumns('comment_automations', ['social_account_id']))->toBeFalse()
        ->and(Schema::hasColumns('comment_automations', ['post_remote_id']))->toBeFalse();
});
