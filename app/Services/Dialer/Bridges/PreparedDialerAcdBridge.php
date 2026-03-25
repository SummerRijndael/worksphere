<?php

namespace App\Services\Dialer\Bridges;

use App\Contracts\DialerAcdBridgeContract;
use App\Models\User;

class PreparedDialerAcdBridge implements DialerAcdBridgeContract
{
    public function prepareOutboundContext(User $user, array $payload = []): array
    {
        return [
            'prepared' => (bool) config('dialer.acd_pipe.prepared', true),
            'connected' => false,
            'mode' => 'outbound_context_only',
            'support_engine' => (string) config('support_chat.routing.engine', 'database'),
            'requested_by' => $user->public_id,
            'requested_at' => now()->toIso8601String(),
        ];
    }

    public function describe(): array
    {
        return [
            'prepared' => (bool) config('dialer.acd_pipe.prepared', true),
            'connected' => (bool) config('dialer.acd_pipe.connected', false),
            'mode' => 'outbound_context_only',
            'support_engine' => (string) config('support_chat.routing.engine', 'database'),
            'message' => 'ACD pipe is prepared for future handoff, but no live bridge is active yet.',
        ];
    }
}
