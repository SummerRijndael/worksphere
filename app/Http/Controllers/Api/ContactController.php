<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RecaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    protected RecaptchaService $recaptcha;

    public function __construct(RecaptchaService $recaptcha)
    {
        $this->recaptcha = $recaptcha;
    }

    /**
     * Handle public contact form submission.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'message' => 'required|string|max:5000',
            'recaptcha_token' => 'required|string',
            'recaptcha_v2_token' => 'nullable|string',
        ]);

        // Check for v2 token first (fallback flow)
        if (! empty($validated['recaptcha_v2_token'])) {
            $v2Verification = $this->recaptcha->verifyV2($validated['recaptcha_v2_token'], $request->ip());
            if (! $v2Verification['success']) {
                return response()->json([
                    'message' => $v2Verification['error'] ?? 'Security challenge failed.',
                ], 422);
            }
        } else {
            // Standard v3 verification
            $verification = $this->recaptcha->verify($validated['recaptcha_token'], 'contact');

            if (! $verification['success']) {
                // Check if it's a score issue (suspicious but not invalid)
                if (isset($verification['score']) && $verification['score'] < config('recaptcha.score_threshold', 0.5)) {
                    return response()->json([
                        'message' => 'Security check required.',
                        'requires_challenge' => true,
                    ], 422);
                }

                return response()->json([
                    'message' => $verification['error'] ?? 'Security check failed.',
                ], 422);
            }
        }

        // Log the contact request (In production, this would send an email)
        Log::info('Public Contact Form Submission', [
            'name' => $validated['name'],
            'email' => $validated['email'],
            // 'message' => $validated['message'], // Optionally truncate or omit
            'ip' => $request->ip(),
            'score' => $verification['score'],
        ]);

        // TODO: Dispatch email notification job here
        // Mail::to(config('mail.admin_address'))->queue(new ContactFormSubmission($validated));

        return response()->json([
            'message' => 'Thank you! Your message has been received.',
        ], 200);
    }
}
