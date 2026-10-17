<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome')->name('home');

// Lets a deploy be verified: the served commit must match the merged one.
Route::get('version', fn () => response()->json(['commit' => config('app.commit')]));

// Meta calls these directly; POST bodies are authenticated by their X-Hub-Signature-256 instead of a CSRF token.
Route::get('webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('webhooks/meta', [MetaWebhookController::class, 'receive'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('webhooks.meta');

// Email-link sign-up sits beside Fortify's register routes, so it shares the same switch.
if (Features::enabled(Features::registration())) {
    Route::post('register/link', [MagicLinkController::class, 'signup'])
        ->middleware(['guest', 'throttle:magic-link'])
        ->name('register.link');
}

Route::post('login/link', [MagicLinkController::class, 'login'])
    ->middleware(['guest', 'throttle:magic-link'])
    ->name('login.link');

Route::post('user/confirm-password/link', [MagicLinkController::class, 'confirm'])
    ->middleware(['auth', 'throttle:magic-link'])
    ->name('password.confirm.link');

Route::get('magic-link/{token}', [MagicLinkController::class, 'consume'])
    ->middleware('throttle:magic-link')
    ->name('magic-link');

// Not behind the registration switch: an invitation is the way in once public sign-up is closed.
Route::get('invitations/{token}', [InvitationController::class, 'show'])
    ->middleware('throttle:10,1')
    ->name('invitations.show');

Route::post('invitations/{token}', [InvitationController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('invitations.store');

Route::inertia('setup-passkey', 'auth/setup-passkey')
    ->middleware(['auth', RequirePassword::class])
    ->name('passkey.setup');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/team.php';
