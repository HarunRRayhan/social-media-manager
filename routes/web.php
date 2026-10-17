<?php

use App\Http\Controllers\Auth\MagicLinkController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome')->name('home');

// Lets a deploy be verified: the served commit must match the merged one.
Route::get('version', fn () => response()->json(['commit' => config('app.commit')]));

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

Route::inertia('setup-passkey', 'auth/setup-passkey')
    ->middleware(['auth', RequirePassword::class])
    ->name('passkey.setup');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
