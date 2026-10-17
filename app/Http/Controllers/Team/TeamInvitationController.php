<?php

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Notifications\InvitedToTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Invite someone by email. The link works for 7 days; only its hash is stored.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        // Emails compare case-sensitively on Postgres, so invitations are stored lowercased.
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('team_invitations')->where('team_id', $team->id)],
            'role' => ['required', Rule::in(['admin', 'member'])],
        ], ['email.unique' => __('That email already has a pending invitation.')]);

        if ($team->users()->whereRaw('lower(email) = ?', [$input['email']])->exists()) {
            throw ValidationException::withMessages(['email' => __('That person is already on the team.')]);
        }

        $token = Str::random(64);

        $team->invitations()->create([...$input, 'token' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);

        Notification::route('mail', $input['email'])->notify(new InvitedToTeam($team->name, route('invitations.show', $token)));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('team-members.index');
    }

    /**
     * Cancel a pending invitation.
     */
    public function destroy(Request $request, int $invitation): RedirectResponse
    {
        $team = $request->user()->currentTeam();
        Gate::authorize('manage', $team);

        $team->invitations()->findOrFail($invitation)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('team-members.index');
    }
}
