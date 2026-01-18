<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
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
}
