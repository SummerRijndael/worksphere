<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validateWithBag('updateProfileInformation');

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
                'username' => $input['username'] ?? $user->username,
                'title' => $input['title'] ?? $user->title,
                'bio' => $input['bio'] ?? $user->bio,
                'location' => $input['location'] ?? $user->location,
                'website' => $input['website'] ?? $user->website,
                'skills' => $input['skills'] ?? $user->skills,
            ])->save();
        }
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
            'username' => $input['username'] ?? $user->username,
            'title' => $input['title'] ?? $user->title,
            'bio' => $input['bio'] ?? $user->bio,
            'location' => $input['location'] ?? $user->location,
            'website' => $input['website'] ?? $user->website,
            'skills' => $input['skills'] ?? $user->skills,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
