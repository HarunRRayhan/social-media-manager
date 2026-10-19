<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs app store reviewers into a demo account, which cannot use an emailed link or a passkey.
 * Works only while REVIEW_LOGIN_TOKEN is set; unset it once the review is done.
 */
class ReviewLoginController extends Controller
{
    private const EMAIL = 'review@sm.harun.dev';

    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $secret = (string) config('services.review.token');

        abort_unless($secret !== '' && hash_equals($secret, $token), 404);

        $user = User::where('email', self::EMAIL)->first()
            ?? User::register(['name' => 'Meta Reviewer', 'username' => 'meta-reviewer', 'email' => self::EMAIL]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
