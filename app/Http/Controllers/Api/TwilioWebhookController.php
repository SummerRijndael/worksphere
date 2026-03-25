<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dialer\DialerService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
    public function __construct(
        protected DialerService $dialerService,
    ) {}

    /**
     * Handle Twilio Debugger Webhook.
     *
     * @see https://www.twilio.com/docs/usage/monitor-alert-webhook
     */
    public function handleDebugger(Request $request)
    {
        // Log the raw payload for inspection
        Log::channel('daily')->info('Twilio Debugger Webhook:', $request->all());

        $payload = $request->all();
        $accountSid = $payload['AccountSid'] ?? null;
        $level = $payload['Level'] ?? 'Unknown'; // Error or Warning
        $sid = $payload['Sid'] ?? null;
        $payloadType = $payload['PayloadType'] ?? null;

        // You might want to extract the "Payload" if it's JSON
        $errorPayload = $payload['Payload'] ?? null;
        if (is_string($errorPayload) && $payloadType === 'application/json') {
            $decoded = json_decode($errorPayload, true);
            if ($decoded) {
                $errorPayload = $decoded;
            }
        }

        Log::error("Twilio Alert [$level]:", [
            'sid' => $sid,
            'account_sid' => $accountSid,
            'payload' => $errorPayload,
        ]);

        return response()->json(['status' => 'received']);
    }

    public function handleVoiceStatus(Request $request): Response
    {
        $payload = $request->all();
        $callSid = (string) ($payload['CallSid'] ?? '');
        $callStatus = (string) ($payload['CallStatus'] ?? '');
        $durationSeconds = isset($payload['CallDuration']) ? (int) $payload['CallDuration'] : null;

        if ($callSid !== '' && $callStatus !== '') {
            $call = $this->dialerService->syncProviderStatus('twilio', $callSid, [
                'status' => $callStatus,
                'duration_seconds' => $durationSeconds,
                'callback_payload' => $payload,
            ]);

            Log::info('Twilio voice status callback processed.', [
                'call_sid' => $callSid,
                'call_status' => $callStatus,
                'matched_call' => $call?->public_id,
            ]);
        }

        return response('', 204);
    }
}
