<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

// Not RefreshDatabase: SQLite rebuilds tables for these schema changes, which only works outside a transaction.
uses(DatabaseMigrations::class);

test('the migration marks each team\'s Default set and puts every account in it', function () {
    $named = User::factory()->create()->currentTeam();
    $namedOther = $named->socialSets()->create(['name' => 'Clients']);
    $unnamed = User::factory()->create()->currentTeam();
    $unnamed->socialSets()->sole()->update(['name' => 'Main']);
    $unnamedOther = $unnamed->socialSets()->create(['name' => 'Clients']);
    $accounts = SocialAccount::factory()->count(2)->for($named)->create();
    $other = SocialAccount::factory()->for($unnamed)->create();
    $other->socialSets()->sync([$unnamedOther->id]);

    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
    DB::table('social_account_social_set')->where('social_account_id', $accounts[0]->id)->delete();

    $this->artisan('migrate')->assertSuccessful();

    expect($named->socialSets()->where('is_default', true)->sole()->name)->toBe('Default')
        ->and($namedOther->fresh()->is_default)->toBeFalse()
        ->and($unnamed->socialSets()->where('is_default', true)->sole()->name)->toBe('Main')
        ->and($accounts[0]->socialSets()->pluck('name')->all())->toBe(['Default'])
        ->and($accounts[1]->socialSets()->pluck('name')->all())->toBe(['Default'])
        ->and($other->socialSets()->orderBy('name')->pluck('name')->all())->toBe(['Clients', 'Main']);
});
