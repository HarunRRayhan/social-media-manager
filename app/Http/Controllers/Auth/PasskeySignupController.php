<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Support\WebAuthn;

class PasskeySignupController extends Controller
{
    use ProfileValidationRules;

    /**
     * Validate the sign-up details and issue WebAuthn registration options.
     *
     * Nothing is persisted here: the user does not exist yet, so the details wait in
     * the session until the ceremony completes.
     */
    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $input = Validator::make($request->all(), $this->profileRules())->validate();

        // shortcut: the package keys the WebAuthn user handle on the model id, which does not exist yet.
        // A random id per ceremony gives each sign-up a unique handle that the store step persists as-is.
        $candidate = (new User($input))->forceFill(['id' => random_int(1, PHP_INT_MAX)]);

        $options = $generate($candidate);

        $request->session()->put([
            'passkey.registration_options' => WebAuthn::toJson($options),
            'passkey.signup' => $input,
        ]);

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Create the account and its first passkey in one transaction, then log the user in.
     */
    public function store(PasskeyRegistrationRequest $request, StorePasskey $storePasskey): JsonResponse
    {
        $input = $request->session()->pull('passkey.signup')
            ?? throw ValidationException::withMessages([
                'credential' => __('Passkey sign-up session expired. Please try again.'),
            ]);

        $input = Validator::make($input, $this->profileRules())->validate();

        // The sign-in password is never shown to the user. The random value keeps the column
        // populated; the user sets a real one through "forgot password".
        $user = DB::transaction(function () use ($request, $storePasskey, $input) {
            $user = User::create([
                ...$input,
                'password' => bin2hex(random_bytes(32)),
            ]);

            $storePasskey(
                $user,
                $request->string('name')->toString(),
                $request->credential(),
                $request->registrationOptions(),
            );

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return response()->json(['redirect' => route('dashboard', absolute: false)]);
    }
}
