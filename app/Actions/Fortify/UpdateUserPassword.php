<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        protected \App\Services\AccountLockoutService $lockoutService,
        protected \App\Services\AuditService $auditService
    ) {}

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'is_password_set' => true,
            'password_last_updated_at' => now(),
        ])->save();

        $this->lockoutService->resetStrikes($user);

        $this->auditService->logAuth(
            action: \App\Enums\AuditAction::PasswordChanged,
            user: $user
        );
    }
}
