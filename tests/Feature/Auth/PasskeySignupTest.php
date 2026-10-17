<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Mockery\MockInterface;
use Tests\TestCase;

class PasskeySignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    /** Sign-up details as the register page posts them. */
    private function signupDetails(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
        ], $overrides);
    }

    /** A "none" attestation credential that deserializes; StorePasskey is mocked in these tests. */
    private function credential(): array
    {
        $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        // CBOR map {fmt: "none", attStmt: {}, authData: 37 zero bytes}.
        $attestationObject = "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData\x58\x25".str_repeat("\0", 37);

        return [
            'id' => 'AQID',
            'rawId' => 'AQID',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $b64('{"type":"webauthn.create","challenge":"AAAA","origin":"http://localhost"}'),
                'attestationObject' => $b64($attestationObject),
            ],
        ];
    }

    public function test_options_validates_details_and_returns_registration_options()
    {
        $response = $this->postJson(route('register.passkey-options'), $this->signupDetails());

        $response->assertOk()
            ->assertJsonStructure(['options' => ['challenge', 'rp' => ['id'], 'user' => ['id', 'name']]])
            ->assertJsonPath('options.user.name', 'test@example.com');

        $this->assertSame(0, User::count());
    }

    public function test_options_rejects_invalid_details_and_stores_nothing()
    {
        User::factory()->create(['username' => 'taken']);

        $this->postJson(route('register.passkey-options'), $this->signupDetails([
            'username' => 'taken',
            'email' => 'not-an-email',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email']);

        $this->assertSame(1, User::count());
        $this->assertNull(session('passkey.signup'));
    }

    public function test_store_creates_one_user_and_passkey_and_logs_in()
    {
        $this->mock(StorePasskey::class, function (MockInterface $mock) {
            $mock->shouldReceive('__invoke')->once()->andReturnUsing(
                fn ($user, $name) => $user->passkeys()->create([
                    'name' => $name,
                    'credential_id' => 'AQID',
                    'credential' => ['type' => 'public-key'],
                ]),
            );
        });

        $this->postJson(route('register.passkey-options'), $this->signupDetails())->assertOk();

        $response = $this->postJson(route('register.passkey'), [
            'name' => 'Test User',
            'credential' => $this->credential(),
        ]);

        $response->assertOk()->assertJsonPath('redirect', route('dashboard', absolute: false));

        $this->assertSame(1, User::count());
        $this->assertSame(1, Passkey::count());

        $user = User::first();
        $this->assertSame('testuser', $user->username);
        $this->assertSame('test@example.com', $user->email);
        $this->assertSame($user->id, Passkey::first()->user_id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_passkey_store_rolls_back_the_user()
    {
        $this->mock(StorePasskey::class, function (MockInterface $mock) {
            $mock->shouldReceive('__invoke')->once()->andThrow(
                InvalidPasskeyException::make('Unable to register this passkey.'),
            );
        });

        $this->postJson(route('register.passkey-options'), $this->signupDetails())->assertOk();

        $this->postJson(route('register.passkey'), [
            'name' => 'Test User',
            'credential' => $this->credential(),
        ])->assertUnprocessable();

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_store_without_options_session_creates_nothing()
    {
        $this->postJson(route('register.passkey'), [
            'name' => 'Test User',
            'credential' => $this->credential(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['credential']);

        $this->assertSame(0, User::count());
    }

    public function test_passkey_signup_routes_are_absent_when_registration_is_disabled()
    {
        $this->assertTrue(Route::has('register.passkey-options'));

        // A real environment variable beats .env (CI copies .env.example, which sets
        // REGISTRATION_DISABLED=false). An in-process reboot does not reliably honor it,
        // so the disabled app boots in a child process.
        $result = Process::env(['REGISTRATION_DISABLED' => 'true'])
            ->run([PHP_BINARY, base_path('artisan'), 'route:list', '--name=register', '--json']);

        $this->assertTrue($result->successful(), $result->errorOutput());

        $names = collect(json_decode($result->output(), true))->pluck('name');

        $this->assertFalse($names->contains('register.passkey-options'));
        $this->assertFalse($names->contains('register.passkey'));
    }
}
