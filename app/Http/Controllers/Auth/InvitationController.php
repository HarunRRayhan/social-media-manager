<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invitation links are the way in while public sign-up is closed, so this works regardless of REGISTRATION_DISABLED.
 */
class InvitationController extends Controller
{
    use ProfileValidationRules;

    /**
     * Open an invitation: join if signed in as the invited email, log in first if the account
     * exists, or show the sign-up form.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = TeamInvitation::findValid($token);

        if (! $invitation) {
            return Inertia::render('auth/accept-invitation', ['status' => 'invalid']);
        }

        $props = ['email' => $invitation->email, 'team' => $invitation->team->name, 'token' => $token];

        if ($user = $request->user()) {
            if (strcasecmp($user->email, $invitation->email) !== 0) {
                return Inertia::render('auth/accept-invitation', ['status' => 'wrong-email', ...$props]);
            }

            $this->join($user, $invitation);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('You joined :team.', ['team' => $props['team']])]);

            return to_route('dashboard');
        }

        if (User::whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->exists()) {
            return redirect()->guest(route('login'))
                ->with('status', __('Log in as :email to join :team.', ['email' => $invitation->email, 'team' => $props['team']]));
        }

        return Inertia::render('auth/accept-invitation', ['status' => 'register', ...$props]);
    }

    /**
     * Create the invited person's account, join the team, and go straight to passkey setup.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = TeamInvitation::findValid($token);

        if (! $invitation || User::where('email', $invitation->email)->exists()) {
            return to_route('invitations.show', $token);
        }

        $input = $request->validate(['name' => $this->nameRules(), 'username' => $this->usernameRules()]);

        $user = DB::transaction(function () use ($input, $invitation) {
            $user = User::register([...$input, 'email' => $invitation->email]);
            $this->join($user, $invitation);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        // The emailed link proved who they are, so they can add a passkey without confirming again.
        $request->session()->passwordConfirmed();

        return to_route('passkey.setup');
    }

    private function join(User $user, TeamInvitation $invitation): void
    {
        DB::transaction(function () use ($user, $invitation) {
            // Someone already on the team keeps their role.
            if (! $invitation->team->users()->whereKey($user->id)->exists()) {
                $invitation->team->users()->attach($user->id, ['role' => $invitation->role]);
            }

            $user->forceFill(['current_team_id' => $invitation->team_id])->save();
            $invitation->delete();
        });
    }
}
