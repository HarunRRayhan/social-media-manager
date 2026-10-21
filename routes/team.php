<?php

use App\Http\Controllers\Team\ApiTokenController;
use App\Http\Controllers\Team\BlueskyConnectionController;
use App\Http\Controllers\Team\CalendarController;
use App\Http\Controllers\Team\CommentAutomationController;
use App\Http\Controllers\Team\ConnectionController;
use App\Http\Controllers\Team\CurrentTeamController;
use App\Http\Controllers\Team\LinkedInConnectionController;
use App\Http\Controllers\Team\MetaConnectionController;
use App\Http\Controllers\Team\PostController;
use App\Http\Controllers\Team\PostListController;
use App\Http\Controllers\Team\SocialSetController;
use App\Http\Controllers\Team\TeamController;
use App\Http\Controllers\Team\TeamInvitationController;
use App\Http\Controllers\Team\TeamMemberController;
use App\Http\Controllers\Team\ThreadsConnectionController;
use App\Http\Controllers\Team\XConnectionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// These pages used to live under team/; keep old links and bookmarks working.
// The query string carries over, so a calendar link keeps its month and set filter.
foreach ([
    'team/calendar' => '/calendar',
    'team/connections' => '/connections',
    'team/social-sets' => '/social-sets',
    'team/api-tokens' => '/settings/api-tokens',
] as $old => $new) {
    Route::get($old, fn (Request $request) => redirect(
        $new.($request->getQueryString() ? '?'.$request->getQueryString() : ''),
        301,
    ));
}

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

    Route::get('social-sets', [SocialSetController::class, 'index'])->name('social-sets.index');
    Route::post('social-sets', [SocialSetController::class, 'store'])->name('social-sets.store');
    Route::patch('social-sets/{socialSet}', [SocialSetController::class, 'update'])
        ->whereNumber('socialSet')
        ->name('social-sets.update');
    Route::delete('social-sets/{socialSet}', [SocialSetController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->whereNumber('socialSet')
        ->name('social-sets.destroy');

    Route::post('social-sets/{socialSet}/restore', [SocialSetController::class, 'restore'])
        ->whereNumber('socialSet')
        ->name('social-sets.restore');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])
        ->whereNumber('post')
        ->name('posts.destroy');
    Route::put('posts/{post}/list', [PostController::class, 'updateList'])
        ->whereNumber('post')
        ->name('posts.list.update');

    Route::get('lists', [PostListController::class, 'index'])->name('lists.index');
    Route::post('lists', [PostListController::class, 'store'])->name('lists.store');
    Route::get('lists/{list}', [PostListController::class, 'show'])->whereNumber('list')->name('lists.show');
    Route::patch('lists/{list}', [PostListController::class, 'update'])->whereNumber('list')->name('lists.update');
    Route::delete('lists/{list}', [PostListController::class, 'destroy'])->whereNumber('list')->name('lists.destroy');
    Route::put('lists/{list}/order', [PostListController::class, 'reorder'])->whereNumber('list')->name('lists.reorder');
    Route::delete('lists/{list}/posts/{post}', [PostListController::class, 'removePost'])
        ->whereNumber('list')
        ->whereNumber('post')
        ->name('lists.posts.destroy');

    Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index');
    Route::put('connections/{account}', [ConnectionController::class, 'update'])
        ->whereNumber('account')
        ->name('connections.update');
    Route::delete('connections/{account}', [ConnectionController::class, 'destroy'])
        ->whereNumber('account')
        ->name('connections.destroy');
    Route::get('connections/meta/redirect', [MetaConnectionController::class, 'redirect'])->name('connect.meta.redirect');
    // Registered in the Meta app as {APP_URL}/connect/meta/callback, so this path must not change.
    Route::get('connect/meta/callback', [MetaConnectionController::class, 'callback'])->name('connect.meta.callback');
    Route::post('connections/meta/pick', [MetaConnectionController::class, 'store'])->name('connect.meta.store');
    Route::delete('connections/meta/pick', [MetaConnectionController::class, 'cancel'])->name('connect.meta.cancel');
    Route::get('connections/threads/redirect', [ThreadsConnectionController::class, 'redirect'])->name('connect.threads.redirect');
    // Registered in the Threads app as {APP_URL}/connect/threads/callback, so this path must not change.
    Route::get('connect/threads/callback', [ThreadsConnectionController::class, 'callback'])->name('connect.threads.callback');
    Route::get('connections/linkedin/redirect', [LinkedInConnectionController::class, 'redirect'])->name('connect.linkedin.redirect');
    // Registered in the LinkedIn app as {APP_URL}/connect/linkedin/callback, so this path must not change.
    Route::get('connect/linkedin/callback', [LinkedInConnectionController::class, 'callback'])->name('connect.linkedin.callback');
    Route::get('connections/x/redirect', [XConnectionController::class, 'redirect'])->name('connect.x.redirect');
    // Registered in the X app as {APP_URL}/connect/x/callback, so this path must not change.
    Route::get('connect/x/callback', [XConnectionController::class, 'callback'])->name('connect.x.callback');
    Route::post('connections/bluesky', [BlueskyConnectionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('connect.bluesky.store');

    Route::get('team/automations', [CommentAutomationController::class, 'index'])->name('automations.index');
    Route::post('team/automations', [CommentAutomationController::class, 'store'])->name('automations.store');
    Route::put('team/automations/{automation}', [CommentAutomationController::class, 'update'])
        ->whereNumber('automation')
        ->name('automations.update');
    Route::delete('team/automations/{automation}', [CommentAutomationController::class, 'destroy'])
        ->whereNumber('automation')
        ->name('automations.destroy');

    Route::get('settings/api-tokens', [ApiTokenController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('api-tokens.edit');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware(RequirePassword::class)
        ->name('api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
        ->whereNumber('token')
        ->name('api-tokens.destroy');
});
