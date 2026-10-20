<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use App\Http\Controllers\Api\V1\SocialSetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('me', MeController::class);
    Route::get('social-sets', SocialSetController::class);
    Route::post('social-sets', [SocialSetController::class, 'store']);
    Route::get('social-accounts', SocialAccountController::class);
    Route::put('social-accounts/{account}/social-sets', [SocialAccountController::class, 'updateSocialSets'])->whereNumber('account');

    Route::get('posts', [PostController::class, 'index']);
    Route::post('posts', [PostController::class, 'store']);
    Route::get('posts/{post}', [PostController::class, 'show'])->whereNumber('post');
    Route::patch('posts/{post}', [PostController::class, 'update'])->whereNumber('post');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
});
