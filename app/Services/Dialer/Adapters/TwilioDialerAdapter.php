<?php

namespace App\Services\Dialer\Adapters;

use App\Contracts\DialerAdapterContract;
use App\Models\DialerCall;
use App\Models\User;
use InvalidArgumentException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client as TwilioClient;

class TwilioDialerAdapter implements DialerAdapterContract
{
    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio Voice';
    }

    public function health(): array
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');
        $from = $this->fromNumber();
        $appSid = (string) config('services.twilio.voice_app_sid');
        $twimlUrl = (string) config('services.twilio.voice_twiml_url');

        $credentialsReady = $sid !== '' && $token !== '';
        $voicePathReady = $appSid !== '' || $twimlUrl !== '';

        return [
            'configured' => $credentialsReady,
            'ready' => $credentialsReady && $from !== '' && $voicePathReady,
            'mode' => 'live',
            'message' => $credentialsReady
                ? ($voicePathReady
                    ? 'Twilio voice adapter is configured.'
                    : 'Add a Twilio Voice App SID or TwiML URL to place calls.')
                : 'Twilio credentials are missing.',
            'caller_id' => $from !== '' ? $from : null,
        ];
    }

    public function capabilities(): array
    {
        return [
            'outbound' => true,
            'hangup' => true,
            'recording' => false,
            'transfer' => true,
            'bridge_ready' => false,
        ];
    }

    public function placeCall(DialerCall $call, User $user, array $payload = []): array
    {
        $this->assertConfigured();

        $client = $this->client();
        $options = [
            'statusCallback' => $this->statusCallbackUrl(),
            'statusCallbackMethod' => 'POST',
            'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
        ];

        $appSid = (string) config('services.twilio.voice_app_sid');
        $twimlUrl = (string) config('services.twilio.voice_twiml_url');

        if ($appSid !== '') {
            $options['applicationSid'] = $appSid;
        } elseif ($twimlUrl !== '') {
            $options['url'] = $twimlUrl;
            $options['method'] = 'POST';
        }

        try {
            $twilioCall = $client->calls->create($call->to_number, $this->fromNumber(), $options);
        } catch (TwilioException $e) {
            throw new InvalidArgumentException('Twilio dial failed: '.$e->getMessage(), previous: $e);
        }

        return [
            'provider_call_id' => $twilioCall->sid,
            'status' => (string) $twilioCall->status,
            'started_at' => null,
            'ended_at' => null,
            'payload' => [
                'sid' => $twilioCall->sid,
                'direction' => $twilioCall->direction ?? null,
                'queue_time' => $twilioCall->queueTime ?? null,
                'subresource_uris' => $twilioCall->subresourceUris ?? [],
            ],
        ];
    }

    public function hangupCall(DialerCall $call, User $user, array $payload = []): array
    {
        $this->assertConfigured();

        if (! $call->provider_call_id) {
            throw new InvalidArgumentException('Twilio call SID is missing.');
        }

        try {
            $this->client()->calls($call->provider_call_id)->update(['status' => 'completed']);
        } catch (TwilioException $e) {
            throw new InvalidArgumentException('Twilio hangup failed: '.$e->getMessage(), previous: $e);
        }

        return [
            'status' => 'completed',
            'ended_at' => now(),
            'payload' => [
                'ended_by' => $user->public_id,
            ],
        ];
    }

    public function transferCall(DialerCall $call, User $user, string $targetNumber, array $payload = []): array
    {
        $this->assertConfigured();

        if (! $call->provider_call_id) {
            throw new InvalidArgumentException('Twilio call SID is missing.');
        }

        $escapedTarget = htmlspecialchars($targetNumber, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $twiml = "<Response><Say voice=\"alice\">Please hold while we transfer your call.</Say><Dial>{$escapedTarget}</Dial></Response>";

        try {
            $this->client()->calls($call->provider_call_id)->update([
                'twiml' => $twiml,
                'statusCallback' => $this->statusCallbackUrl(),
                'statusCallbackMethod' => 'POST',
            ]);
        } catch (TwilioException $e) {
            throw new InvalidArgumentException('Twilio transfer failed: '.$e->getMessage(), previous: $e);
        }

        return [
            'status' => 'completed',
            'ended_at' => now(),
            'payload' => [
                'transfer_to' => $targetNumber,
                'transferred_by' => $user->public_id,
                'transferred_at' => now()->toIso8601String(),
            ],
        ];
    }

    protected function client(): TwilioClient
    {
        return new TwilioClient(
            (string) config('services.twilio.sid'),
            (string) config('services.twilio.token'),
        );
    }

    protected function fromNumber(): string
    {
        return (string) (config('services.twilio.voice_from') ?: config('services.twilio.from'));
    }

    protected function statusCallbackUrl(): string
    {
        return (string) (config('services.twilio.voice_status_callback_url')
            ?: url('/api/webhooks/twilio/voice/status'));
    }

    protected function assertConfigured(): void
    {
        $health = $this->health();

        if (! ($health['ready'] ?? false)) {
            throw new InvalidArgumentException((string) ($health['message'] ?? 'Twilio voice adapter is not ready.'));
        }
    }
}
