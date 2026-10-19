<?php

use App\Http\Controllers\Team\ApiTokenController;
use App\Http\Controllers\Team\CommentAutomationController;
use App\Http\Controllers\Team\ConnectionController;
use App\Http\Controllers\Team\CurrentTeamController;
use App\Http\Controllers\Team\MetaConnectionController;
use App\Http\Controllers\Team\SocialSetController;
use App\Http\Controllers\Team\TeamController;
use App\Http\Controllers\Team\TeamInvitationController;
use App\Http\Controllers\Team\TeamMemberController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Every team page works on the user's current team.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('current-team', [CurrentTeamController::class, 'update'])->name('current-team.update');
    Route::post('teams', [TeamController::class, 'store'])->name('teams.store');

    Route::redirect('team', '/team/general');
    Route::get('team/general', [TeamController::class, 'edit'])->name('team.edit');
    Route::patch('team/general', [TeamController::class, 'update'])->name('team.update');
    Route::delete('team', [TeamController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->name('team.destroy');

    Route::get('team/members', [TeamMemberController::class, 'index'])->name('team-members.index');
    Route::patch('team/members/{member}', [TeamMemberController::class, 'update'])
        ->whereNumber('member')
        ->name('team-members.update');
    Route::delete('team/members/{member}', [TeamMemberController::class, 'destroy'])
        ->whereNumber('member')
        ->name('team-members.destroy');

    Route::post('team/invitations', [TeamInvitationController::class, 'store'])
        ->middleware('throttle:magic-link')
        ->name('team-invitations.store');
    Route::delete('team/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])
        ->whereNumber('invitation')
        ->name('team-invitations.destroy');

    Route::get('team/social-sets', [SocialSetController::class, 'index'])->name('social-sets.index');
    Route::post('team/social-sets', [SocialSetController::class, 'store'])->name('social-sets.store');
    Route::patch('team/social-sets/{socialSet}', [SocialSetController::class, 'update'])
        ->whereNumber('socialSet')
        ->name('social-sets.update');
    Route::delete('team/social-sets/{socialSet}', [SocialSetController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->whereNumber('socialSet')
        ->name('social-sets.destroy');

    Route::post('team/social-sets/{socialSet}/restore', [SocialSetController::class, 'restore'])
        ->whereNumber('socialSet')
        ->name('social-sets.restore');

    Route::get('team/connections', [ConnectionController::class, 'index'])->name('connections.index');
    Route::put('team/connections/{account}', [ConnectionController::class, 'update'])
        ->whereNumber('account')
        ->name('connections.update');
    Route::delete('team/connections/{account}', [ConnectionController::class, 'destroy'])
        ->whereNumber('account')
        ->name('connections.destroy');
    Route::get('team/connections/meta/redirect', [MetaConnectionController::class, 'redirect'])->name('connect.meta.redirect');
    // Registered in the Meta app as {APP_URL}/connect/meta/callback, so this path must not change.
    Route::get('connect/meta/callback', [MetaConnectionController::class, 'callback'])->name('connect.meta.callback');
    Route::post('team/connections/meta/pick', [MetaConnectionController::class, 'store'])->name('connect.meta.store');
    Route::delete('team/connections/meta/pick', [MetaConnectionController::class, 'cancel'])->name('connect.meta.cancel');

    Route::get('team/automations', [CommentAutomationController::class, 'index'])->name('automations.index');
    Route::post('team/automations', [CommentAutomationController::class, 'store'])->name('automations.store');
    Route::put('team/automations/{automation}', [CommentAutomationController::class, 'update'])
        ->whereNumber('automation')
        ->name('automations.update');
    Route::delete('team/automations/{automation}', [CommentAutomationController::class, 'destroy'])
        ->whereNumber('automation')
        ->name('automations.destroy');

    Route::get('team/api-tokens', [ApiTokenController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('api-tokens.edit');
    Route::post('team/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware(RequirePassword::class)
        ->name('api-tokens.store');
    Route::delete('team/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
        ->whereNumber('token')
        ->name('api-tokens.destroy');
});
