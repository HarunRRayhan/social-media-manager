<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamMemberController extends Controller
{
    /**
     * Show the current team's members and pending invitations.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam();

        return Inertia::render('team/members', [
            'members' => $team->users()->orderBy('name')->get()->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->getRelation('pivot')->role,
            ])->all(),
            'invitations' => $team->invitations()->latest('id')->get()->map(fn ($invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expired' => $invitation->expires_at->isPast(),
                'expires_at_diff' => $invitation->expires_at->diffForHumans(),
            ])->all(),
            'canManage' => $user->can('manage', $team),
        ]);
    }

    /**
     * Change a member's role. The owner's role is fixed in this version.
     */
    public function update(Request $request, int $member): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $target = $team->users()->findOrFail($member);
        abort_if($target->getRelation('pivot')->role === 'owner', 403, __('The owner role cannot be changed.'));

        $role = $request->validate(['role' => ['required', Rule::in(['admin', 'member'])]])['role'];
        $team->users()->updateExistingPivot($target->id, ['role' => $role]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('team-members.index');
    }

    /**
     * Remove a member, or leave the team when it is yourself. The owner can do neither.
     */
    public function destroy(Request $request, int $member): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam();
        $target = $team->users()->findOrFail($member);

        abort_if($target->getRelation('pivot')->role === 'owner', 403, __('The owner cannot leave or be removed.'));

        if ($target->id !== $user->id) {
            Gate::authorize('manage', $team);
        }

        $team->users()->detach($target->id);

        if ($target->id === $user->id) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('You left :team.', ['team' => $team->name])]);

            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('team-members.index');
    }
}
