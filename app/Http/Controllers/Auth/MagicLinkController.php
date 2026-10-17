<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Notifications\MagicLink;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MagicLinkController extends Controller
{
    use ProfileValidationRules;

    /**
     * Email a login link. The reply is the same whether or not the account exists.
     */
    public function login(Request $request): RedirectResponse
    {
        $login = $request->validate(['login' => ['required', 'string', 'max:255']])['login'];

        $user = User::where('username', $login)->orWhere('email', $login)->first();

        if ($user) {
            $this->send($user->email, ['type' => 'login', 'user' => $user->id, 'email' => $user->email]);
        }

        return back()->with('status', __('If that account exists, a login link is on its way.'));
    }

    /**
     * Email a sign-up link. The account is only created once the link is opened,
     * which also proves the email address belongs to the person signing up.
     */
    public function signup(Request $request): RedirectResponse
    {
        $input = Validator::make($request->all(), $this->profileRules())->validate();

        $this->send($input['email'], ['type' => 'signup', 'input' => $input]);

        return back()->with('status', __('Check your email for a link to finish signing up.'));
    }

    /**
     * Email a link that confirms the signed-in user, in place of a password.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $this->send($request->user()->email, ['type' => 'confirm', 'user' => $request->user()->id]);

        return back()->with('status', __('We emailed you a confirmation link.'));
    }

    /**
     * Open a link: each one works once, for 15 minutes.
     */
    public function consume(Request $request, string $token): RedirectResponse
    {
        $link = Cache::pull($this->key($token));

        return match ($link['type'] ?? null) {
            'login' => $this->startSession($request, User::whereKey($link['user'])->where('email', $link['email'])->first()),
            'signup' => $this->createAccount($request, $link['input']),
            'confirm' => $this->confirmUser($request, $link['user']),
            default => $this->expired(),
        };
    }

    private function startSession(Request $request, ?User $user): RedirectResponse
    {
        if (! $user) {
            return $this->expired();
        }

        // Opening the link proves the inbox is theirs.
        $user->markEmailAsVerified();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * @param  array<string, string>  $input
     */
    private function createAccount(Request $request, array $input): RedirectResponse
    {
        $validator = Validator::make($input, $this->profileRules());

        if ($validator->fails()) {
            return redirect()->route('register')->withErrors($validator);
        }

        // The password is never shown or used; the random value only fills the column.
        $user = User::create([...$validator->validated(), 'password' => bin2hex(random_bytes(32))]);
        $user->markEmailAsVerified();

        event(new Registered($user));

        $this->startSession($request, $user);

        // The link just proved who they are, so they can add a passkey without confirming again.
        $request->session()->passwordConfirmed();

        return redirect()->route('passkey.setup');
    }

    private function confirmUser(Request $request, int $userId): RedirectResponse
    {
        if ($request->user()?->id !== $userId) {
            return $this->expired();
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function expired(): RedirectResponse
    {
        return redirect()->route('login')->with('status', __('That link has expired or was already used.'));
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function send(string $email, array $link): void
    {
        $token = Str::random(64);

        Cache::put($this->key($token), $link, now()->addMinutes(15));

        Notification::route('mail', $email)->notify(new MagicLink(route('magic-link', $token), $link['type']));
    }

    private function key(string $token): string
    {
        return 'magic-link:'.hash('sha256', $token);
    }
}
