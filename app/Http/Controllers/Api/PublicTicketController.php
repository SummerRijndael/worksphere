<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\RecaptchaService;
use Illuminate\Http\Request;

class PublicTicketController extends Controller
{
    protected RecaptchaService $recaptcha;

    public function __construct(RecaptchaService $recaptcha)
    {
        $this->recaptcha = $recaptcha;
    }

    /**
     * Submit a ticket as a guest or client.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'nullable|string', // Optional categorization
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
            // Verify reCAPTCHA v3
            $verification = $this->recaptcha->verify($validated['recaptcha_token'], 'support_ticket');

            if (! $verification['success']) {
                // Check if it's a score issue
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

        // Check if user exists with this email
        $user = User::where('email', $validated['email'])->first();

        $ticketData = [
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => 'open',
            'priority' => 'medium',
            'type' => 'question', // Default type
        ];

        if ($user) {
            $ticketData['reporter_id'] = $user->id;
        } else {
            $ticketData['guest_name'] = $validated['name'];
            $ticketData['guest_email'] = $validated['email'];
        }

        $ticket = Ticket::create($ticketData);

        // Optional: Send auto-responder email here

        return response()->json([
            'message' => 'Ticket submitted successfully!',
            'ticket_number' => $ticket->ticket_number,
        ], 201);
    }
}
