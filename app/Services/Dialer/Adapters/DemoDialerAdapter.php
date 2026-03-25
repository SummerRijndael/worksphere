<?php

namespace App\Services\Dialer\Adapters;

use App\Contracts\DialerAdapterContract;
use App\Models\DialerCall;
use App\Models\User;

class DemoDialerAdapter implements DialerAdapterContract
{
    public function key(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return (string) config('dialer.demo.label', 'Demo Line');
    }

    public function health(): array
    {
        return [
            'configured' => true,
            'ready' => true,
            'mode' => 'demo',
            'message' => 'Simulated adapter for local dialing workflows.',
            'caller_id' => (string) config('dialer.demo.caller_id', '+10000000000'),
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
        return [
            'provider_call_id' => null,
            'status' => 'in_progress',
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => 0,
            'payload' => [
                'simulated' => true,
                'initiated_by' => $user->public_id,
            ],
        ];
    }

    public function hangupCall(DialerCall $call, User $user, array $payload = []): array
    {
        return [
            'status' => 'completed',
            'ended_at' => now(),
            'payload' => [
                'simulated' => true,
                'ended_by' => $user->public_id,
            ],
        ];
    }

    public function transferCall(DialerCall $call, User $user, string $targetNumber, array $payload = []): array
    {
        return [
            'status' => 'completed',
            'ended_at' => now(),
            'payload' => [
                'simulated' => true,
                'transfer_to' => $targetNumber,
                'transferred_by' => $user->public_id,
                'transferred_at' => now()->toIso8601String(),
            ],
        ];
    }
}
