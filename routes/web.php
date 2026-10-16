<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Lets a deploy be verified: the served commit must match the merged one.
Route::get('version', fn () => response()->json(['commit' => config('app.commit')]));

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
