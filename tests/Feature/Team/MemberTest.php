<?php

use App\Models\User;
use App\Notifications\InvitedToTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = $this->owner->currentTeam();
});

test('the members page lists members and invitations', function () {
    memberOf($this->team, 'admin');
    $this->team->invitations()->create(['email' => 'new@example.com', 'role' => 'member', 'token' => hash('sha256', 'x'), 'expires_at' => now()->addDay()]);

    $this->actingAs($this->owner)
        ->get(route('team-members.index'))
        ->assertInertia(fn ($page) => $page->component('team/members')->has('members', 2)->has('invitations', 1)->where('canManage', true));
});

test('owners and admins can invite, members cannot', function () {
    Notification::fake();

    $this->actingAs(memberOf($this->team, 'member'))
        ->post(route('team-invitations.store'), ['email' => 'a@example.com', 'role' => 'member'])
        ->assertForbidden();

    $this->actingAs(memberOf($this->team, 'admin'))
        ->post(route('team-invitations.store'), ['email' => 'a@example.com', 'role' => 'admin'])
        ->assertRedirect(route('team-members.index'));

    $invitation = $this->team->invitations()->sole();
    expect($invitation->role)->toBe('admin')
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();

    Notification::assertSentTo(new AnonymousNotifiable, InvitedToTeam::class, function (InvitedToTeam $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation) {
        $url = $notification->toMail($notifiable)->actionUrl;

        // Only the hash is stored, so the emailed token is not the stored one.
        return $notifiable->routes['mail'] === 'a@example.com' && ! str_contains($url, $invitation->token);
    });
});

test('an invitation cannot be sent twice, to a member, or with the owner role', function () {
    Notification::fake();
    $this->actingAs($this->owner);

    $this->post(route('team-invitations.store'), ['email' => 'a@example.com', 'role' => 'member']);
    $this->post(route('team-invitations.store'), ['email' => 'a@example.com', 'role' => 'member'])->assertSessionHasErrors('email');
    $this->post(route('team-invitations.store'), ['email' => $this->owner->email, 'role' => 'member'])->assertSessionHasErrors('email');
    $this->post(route('team-invitations.store'), ['email' => 'b@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');

    expect($this->team->invitations()->count())->toBe(1);
});

test('an invitation can be cancelled by a manager only', function () {
    $invitation = $this->team->invitations()->create(['email' => 'a@example.com', 'role' => 'member', 'token' => hash('sha256', 'x'), 'expires_at' => now()->addDay()]);

    $this->actingAs(memberOf($this->team, 'member'))->delete(route('team-invitations.destroy', $invitation))->assertForbidden();
    $this->actingAs(User::factory()->create())->delete(route('team-invitations.destroy', $invitation))->assertNotFound();
    $this->actingAs($this->owner)->delete(route('team-invitations.destroy', $invitation))->assertRedirect();

    $this->assertModelMissing($invitation);
});

test('managers can change roles but never the owners', function () {
    $member = memberOf($this->team, 'member');
    $admin = memberOf($this->team, 'admin');

    $this->actingAs($member)->patch(route('team-members.update', $admin), ['role' => 'member'])->assertForbidden();

    $this->actingAs($admin)->patch(route('team-members.update', $member), ['role' => 'admin'])->assertRedirect();
    expect($member->teamRole($this->team))->toBe('admin');

    $this->actingAs($admin)->patch(route('team-members.update', $this->owner), ['role' => 'member'])->assertForbidden();
    $this->actingAs($admin)->patch(route('team-members.update', $member), ['role' => 'owner'])->assertSessionHasErrors('role');
    expect($this->owner->teamRole($this->team))->toBe('owner');
});

test('managers can remove members, members cannot, and the owner stays', function () {
    $member = memberOf($this->team, 'member');
    $admin = memberOf($this->team, 'admin');

    $this->actingAs($member)->delete(route('team-members.destroy', $admin))->assertForbidden();
    $this->actingAs($admin)->delete(route('team-members.destroy', $this->owner))->assertForbidden();

    $this->actingAs($admin)->delete(route('team-members.destroy', $member))->assertRedirect(route('team-members.index'));
    expect($member->teamRole($this->team))->toBeNull();

    $outsider = User::factory()->create();
    $this->actingAs($admin)->delete(route('team-members.destroy', $outsider))->assertNotFound();
});

test('a member can leave, the owner cannot', function () {
    $member = memberOf($this->team, 'member');

    $this->actingAs($member)->delete(route('team-members.destroy', $member))->assertRedirect(route('dashboard'));
    expect($member->teamRole($this->team))->toBeNull()
        ->and($member->fresh()->currentTeam()->id)->not->toBe($this->team->id);

    $this->actingAs($this->owner)->delete(route('team-members.destroy', $this->owner))->assertForbidden();
    expect($this->owner->teamRole($this->team))->toBe('owner');
});
