<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = User::factory()->create()->currentTeam();
});

function invite(Team $team, string $email, string $role = 'member', ?DateTimeInterface $expiresAt = null): string
{
    $token = Str::random(64);
    $team->invitations()->create(['email' => $email, 'role' => $role, 'token' => hash('sha256', $token), 'expires_at' => $expiresAt ?? now()->addDays(7)]);

    return $token;
}

test('a signed-in invitee joins the team and switches to it', function () {
    $user = User::factory()->create(['email' => 'a@example.com']);
    $token = invite($this->team, 'a@example.com', 'admin');

    $this->actingAs($user)->get(route('invitations.show', $token))->assertRedirect(route('dashboard'));

    expect($user->teamRole($this->team))->toBe('admin')
        ->and($user->fresh()->current_team_id)->toBe($this->team->id)
        ->and(TeamInvitation::count())->toBe(0);
});

test('a signed-in user with another email is told and does not join', function () {
    $user = User::factory()->create();
    $token = invite($this->team, 'a@example.com');

    $this->actingAs($user)
        ->get(route('invitations.show', $token))
        ->assertInertia(fn ($page) => $page->component('auth/accept-invitation')->where('status', 'wrong-email'));

    expect($user->teamRole($this->team))->toBeNull()
        ->and(TeamInvitation::count())->toBe(1);
});

test('a guest with an account logs in first and lands back on the invitation', function () {
    $user = User::factory()->create(['email' => 'a@example.com']);
    $token = invite($this->team, 'a@example.com');

    $this->get(route('invitations.show', $token))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    expect(session('url.intended'))->toBe(route('invitations.show', $token));
    expect($user->teamRole($this->team))->toBeNull();
});

test('a new person signs up from the invitation even with registration disabled', function () {
    config(['fortify.features' => array_values(array_filter(config('fortify.features'), fn ($f) => $f !== 'registration'))]);
    $token = invite($this->team, 'new@example.com');

    $this->get(route('invitations.show', $token))
        ->assertInertia(fn ($page) => $page->component('auth/accept-invitation')->where('status', 'register')->where('email', 'new@example.com'));

    $this->post(route('invitations.store', $token), ['name' => 'New Person', 'username' => 'newperson'])
        ->assertRedirect(route('passkey.setup'));

    $user = User::where('email', 'new@example.com')->sole();
    $this->assertAuthenticatedAs($user);

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->teamRole($this->team))->toBe('member')
        ->and($user->current_team_id)->toBe($this->team->id)
        ->and($user->teams()->count())->toBe(2)
        ->and(TeamInvitation::count())->toBe(0);

    $this->get(route('passkey.setup'))->assertOk();
});

test('the sign-up form validates the username', function () {
    User::factory()->create(['username' => 'taken']);
    $token = invite($this->team, 'new@example.com');

    $this->post(route('invitations.store', $token), ['name' => 'New', 'username' => 'taken'])->assertSessionHasErrors('username');

    $this->assertGuest();
});

test('expired, used and unknown invitations show a clear message', function (string $case) {
    $token = match ($case) {
        'expired' => invite($this->team, 'a@example.com', expiresAt: now()->subMinute()),
        'unknown' => 'nope',
        'used' => tap(invite($this->team, 'a@example.com'), fn () => TeamInvitation::query()->delete()),
    };

    $this->get(route('invitations.show', $token))
        ->assertInertia(fn ($page) => $page->component('auth/accept-invitation')->where('status', 'invalid'));

    $this->post(route('invitations.store', $token), ['name' => 'A', 'username' => 'aaa'])->assertRedirect(route('invitations.show', $token));
    expect(User::where('email', 'a@example.com')->exists())->toBeFalse();
})->with(['expired', 'unknown', 'used']);
