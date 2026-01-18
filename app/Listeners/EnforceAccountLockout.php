<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AccountLockoutService;
use Illuminate\Auth\Events\Lockout;

class EnforceAccountLockout
{
    public function __construct(
        protected AccountLockoutService $lockoutService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(Lockout $event): void
    {
        // Identify user from request
        $email = $event->request->input('email') ?? $event->request->input('username');

        if (! $email) {
            return;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $this->lockoutService->handleLockout($user);
        }
    }
}
