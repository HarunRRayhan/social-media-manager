<?php

use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\PasskeySignupController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome')->name('home');

// Lets a deploy be verified: the served commit must match the merged one.
Route::get('version', fn () => response()->json(['commit' => config('app.commit')]));

// Passkey sign-up sits beside Fortify's register routes, so it shares the same switch.
if (Features::enabled(Features::registration())) {
    Route::middleware(['guest', 'throttle:passkeys'])->group(function () {
        Route::post('register/passkey/options', [PasskeySignupController::class, 'options'])
            ->name('register.passkey-options');

        Route::post('register/passkey', [PasskeySignupController::class, 'store'])
            ->name('register.passkey');
    });

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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
