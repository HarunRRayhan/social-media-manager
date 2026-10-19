<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is off while no token is set', function () {
    config(['services.review.token' => null]);

    $this->get('/review-login/anything')->assertNotFound();
    $this->assertGuest();
});

it('refuses a wrong token', function () {
    config(['services.review.token' => 'right-token']);

    $this->get('/review-login/wrong-token')->assertNotFound();
    $this->assertGuest();
});

it('signs in to the same demo account each time', function () {
    config(['services.review.token' => 'right-token']);

    $this->get('/review-login/right-token')->assertRedirect('/dashboard');
    $this->assertAuthenticated();
    auth()->logout();

    $this->get('/review-login/right-token')->assertRedirect('/dashboard');

    expect(User::where('email', 'review@sm.harun.dev')->count())->toBe(1);
});
