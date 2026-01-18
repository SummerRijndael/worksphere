<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'username' => $input['username'] ?? explode('@', $input['email'])[0].rand(100, 999),
            'password' => Hash::make($input['password']),
            'status' => 'active',
            'is_password_set' => true,
            'password_last_updated_at' => now(),
            'preferences' => [
                'appearance' => [
                    'mode' => 'system',
                    'color' => 'default',
                ],
                'notifications' => [
                    'email' => true,
                    'push' => true,
                ],
            ],
        ]);
        // Dispatch the Registered event to trigger email verification
        event(new \Illuminate\Auth\Events\Registered($user));

        return $user;
    }
}
