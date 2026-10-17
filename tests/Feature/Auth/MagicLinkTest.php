<?php

use App\Models\User;
use App\Notifications\MagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => Notification::fake());

function sentLink(): string
{
    $url = null;

    Notification::assertSentTo(new AnonymousNotifiable, MagicLink::class, function (MagicLink $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url) {
        $url = $notification->toMail($notifiable)->actionUrl;

        return true;
    });

    return parse_url($url, PHP_URL_PATH);
}

test('a login link logs the user in once', function () {
    $user = User::factory()->unverified()->create();

    $this->post(route('login.link'), ['login' => $user->username])->assertSessionHas('status');

    $link = sentLink();

    $this->get($link)->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    auth()->logout();

    $this->get($link)->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a login link can be requested by email', function () {
    $user = User::factory()->create();

    $this->post(route('login.link'), ['login' => $user->email]);

    $this->get(sentLink());
    $this->assertAuthenticatedAs($user);
});

test('an unknown account gets the same reply and no email', function () {
    $this->post(route('login.link'), ['login' => 'nobody'])->assertSessionHas('status');

    Notification::assertNothingSent();
});

test('a sign-up link creates a verified account', function () {
    $this->post(route('register.link'), [
        'name' => 'Harun',
        'username' => 'harun',
        'email' => 'harun@example.com',
    ])->assertSessionHas('status');

    $this->assertDatabaseMissing('users', ['username' => 'harun']);

    $this->get(sentLink())->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('username', 'harun')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('a sign-up link fails if the username was taken meanwhile', function () {
    $this->post(route('register.link'), ['name' => 'Harun', 'username' => 'harun', 'email' => 'harun@example.com']);

    User::factory()->create(['username' => 'harun']);

    $this->get(sentLink())->assertRedirect(route('register'))->assertSessionHasErrors('username');
    $this->assertGuest();
});

test('a confirmation link confirms only its own user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('password.confirm.link'));
    $link = sentLink();

    $this->actingAs(User::factory()->create())->get($link)->assertRedirect(route('login'));
    $this->assertNull(session('auth.password_confirmed_at'));

    Notification::fake();
    $this->actingAs($user)->post(route('password.confirm.link'));

    $this->get(sentLink())->assertRedirect();
    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});
