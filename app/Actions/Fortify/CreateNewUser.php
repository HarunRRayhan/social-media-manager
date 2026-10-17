<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Refuse Fortify's password sign-up: accounts are created by passkey or email link.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        throw ValidationException::withMessages([
            'email' => __('Sign up with a passkey or an email link.'),
        ]);
    }
}
