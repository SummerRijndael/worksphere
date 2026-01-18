<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class TwoFactorSmsService
{
    protected ?Client $twilio = null;

    protected ?string $verifySid = null;

    public function __construct()
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $this->verifySid = config('services.twilio.verify_sid');

        if ($sid && $token) {
            $this->twilio = new Client($sid, $token);
        }
    }

    /**
     * Generate and send a verification code via SMS using Twilio Verify.
     */
    public function sendVerificationCode(User $user): bool
    {
        if (! $this->twilio) {
            Log::error('Twilio not configured', [
                'sid' => config('services.twilio.sid') ? 'present' : 'missing',
                'token' => config('services.twilio.token') ? 'present' : 'missing',
            ]);

            return false;
        }

        if (! $this->verifySid) {
            Log::error('Twilio Verify Service SID not configured');

            return false;
        }

        if (! $user->phone) {
            Log::error('User phone number not set', ['user_id' => $user->id]);

            return false;
        }

        try {
            // Use Twilio Verify API
            $verification = $this->twilio->verify->v2->services($this->verifySid)
                ->verifications
                ->create($user->phone, 'sms');

            Log::info('SMS verification sent', [
                'user_id' => $user->id,
                'status' => $verification->status,
            ]);

            return $verification->status === 'pending';
        } catch (\Exception $e) {
            Log::error('Failed to send SMS verification code', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify the code submitted by the user using Twilio Verify.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $this->twilio || ! $this->verifySid) {
            return false;
        }

        if (! $user->phone) {
            return false;
        }

        try {
            $verificationCheck = $this->twilio->verify->v2->services($this->verifySid)
                ->verificationChecks
                ->create([
                    'to' => $user->phone,
                    'code' => $code,
                ]);

            Log::info('SMS verification check', [
                'user_id' => $user->id,
                'status' => $verificationCheck->status,
            ]);

            return $verificationCheck->status === 'approved';
        } catch (\Exception $e) {
            Log::error('Failed to verify SMS code', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
