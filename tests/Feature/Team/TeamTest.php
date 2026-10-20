<?php

use App\Models\Post;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('every account starts with a personal team holding one Default set', function () {
    $user = User::factory()->create(['name' => 'Harun']);
    $team = $user->currentTeam();

    expect($team->name)->toBe("Harun's Team")
        ->and($user->teamRole($team))->toBe('owner')
        ->and($team->socialSets()->pluck('name')->all())->toBe(['Default'])
        ->and($user->current_team_id)->toBe($team->id);
});

test('a user without a current team gets one on their next page', function () {
    $user = User::factory()->create();
    $user->teams()->first()->delete();

    expect($user->fresh()->current_team_id)->toBeNull();

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.currentTeam.name', "{$user->name}'s Team")->has('auth.teams', 1));
});

test('teams are shared with every page', function () {
    $user = User::factory()->create();
    $other = memberOf($team = Team::factory()->create(['name' => 'Acme']), 'admin');

    $this->actingAs($other)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.currentTeam', ['id' => $team->id, 'name' => 'Acme', 'role' => 'admin'])
            ->has('auth.teams', 2));
});

test('a team can be created and becomes current', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('teams.store'), ['name' => 'Clients'])->assertRedirect(route('team.edit'));

    $team = $user->fresh()->currentTeam();
    expect($team->name)->toBe('Clients')
        ->and($user->teamRole($team))->toBe('owner')
        ->and($team->socialSets()->count())->toBe(1);
});

test('a user can switch only to their own teams', function () {
    $user = User::factory()->create();
    $personal = $user->currentTeam();
    $team = Team::createFor($user, 'Second');

    $this->actingAs($user)->put(route('current-team.update'), ['team_id' => $personal->id])->assertRedirect();
    expect($user->fresh()->current_team_id)->toBe($personal->id);

    $foreign = User::factory()->create()->currentTeam();
    $this->actingAs($user)->put(route('current-team.update'), ['team_id' => $foreign->id])->assertNotFound();
    expect($user->fresh()->current_team_id)->toBe($personal->id);

    expect($team->exists)->toBeTrue();
});

test('owners and admins can rename the team, members cannot', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam();

    $this->actingAs(memberOf($team, 'member'))->patch(route('team.update'), ['name' => 'Nope'])->assertForbidden();
    $this->actingAs(memberOf($team, 'admin'))->patch(route('team.update'), ['name' => 'Renamed'])->assertRedirect(route('team.edit'));

    expect($team->fresh()->name)->toBe('Renamed');
});

test('only the owner can delete a team, and not their only one', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam();
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->actingAs(memberOf($team, 'admin'))->delete(route('team.destroy'))->assertForbidden();

    $this->actingAs($owner)->delete(route('team.destroy'))->assertSessionHasErrors('team');
    $this->assertModelExists($team);

    Team::createFor($owner, 'Second');
    $owner->forceFill(['current_team_id' => $team->id])->save();
    $team->createToken('CM');
    Post::factory()->for($team->socialSets()->sole())->create();

    $this->actingAs($owner)->delete(route('team.destroy'))->assertRedirect(route('dashboard'));

    $this->assertModelMissing($team);
    expect(Post::count())->toBe(0)
        ->and($team->tokens()->count())->toBe(0);
});

test('deleting a team requires a recent confirmation', function () {
    $this->actingAs(User::factory()->create())
        ->delete(route('team.destroy'))
        ->assertRedirect(route('password.confirm'));
});

test('deleting an account deletes the teams it owns but not the ones it joined', function () {
    $user = User::factory()->create();
    $owned = $user->currentTeam();
    $joined = User::factory()->create()->currentTeam();
    $joined->users()->attach($user, ['role' => 'admin']);

    $user->delete();

    $this->assertModelMissing($owned);
    $this->assertModelExists($joined);
});

test('switching team stays on the current page', function () {
    $user = User::factory()->create();
    $team = Team::createFor($user, 'Second');

    $this->actingAs($user)
        ->from(route('calendar.index'))
        ->put(route('current-team.update'), ['team_id' => $team->id])
        ->assertRedirect(route('calendar.index'));
});

test('moved pages live outside team/ and their old URLs redirect permanently', function (string $old, string $route) {
    expect(route($route, absolute: false))->not->toStartWith('/team/');

    $this->get("{$old}?month=2026-10")->assertStatus(301)->assertRedirect(route($route, ['month' => '2026-10']));
})->with([
    ['/team/calendar', 'calendar.index'],
    ['/team/connections', 'connections.index'],
    ['/team/social-sets', 'social-sets.index'],
    ['/team/api-tokens', 'api-tokens.edit'],
]);

test('meta and threads callback paths do not move', function () {
    expect(route('connect.meta.callback', absolute: false))->toBe('/connect/meta/callback')
        ->and(route('connect.threads.callback', absolute: false))->toBe('/connect/threads/callback');
});
