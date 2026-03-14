<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\CallEnded;
use App\Events\Chat\CallInitiated;
use App\Events\Chat\CallSignal;
use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Models\Chat\CallSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Notifications\Chat\MissedCallNotification;
use Illuminate\Support\Str;

class VideoCallController extends Controller
{
    /**
     * Verify that the current user is a participant in the chat.
     */
    private function findChatOrFail(Chat $chat): Chat
    {
        $userId = Auth::id();

        $isParticipant = $chat->participants()
            ->where('user_id', $userId)
            ->exists();

        if (! $isParticipant) {
            abort(404, 'Chat not found.');
        }

        return $chat;
    }

    /**
     * Generate short-lived TURN credentials via Cloudflare API.
     * Falls back to STUN-only config if TURN is not configured.
     */
    public function turnCredentials(Chat $chat): JsonResponse
    {
        $this->findChatOrFail($chat);

        // Default fallback: STUN-only
        $iceServers = [
            [
                'urls' => 'stun:stun.cloudflare.com:3478',
            ],
        ];

        $turnKeyId = config('services.cloudflare.turn_key_id');
        $turnApiToken = config('services.cloudflare.turn_api_token');

        if ($turnKeyId && $turnApiToken) {
            try {
                $ttl = (int) config('services.cloudflare.turn_credential_ttl', 14400);
                $ttl = max(60, min($ttl, 172800));

                $customIdentifier = null;
                if (Auth::check()) {
                    $customIdentifier = 'chat:'.$chat->public_id.':user:'.Auth::id();
                }

                $payload = ['ttl' => $ttl];
                if ($customIdentifier) {
                    $payload['customIdentifier'] = $customIdentifier;
                }

                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::withToken($turnApiToken)
                    ->post("https://rtc.live.cloudflare.com/v1/turn/keys/{$turnKeyId}/credentials/generate-ice-servers", $payload);

                if ($response->successful()) {
                    $data = $response->json();

                    // Cloudflare returns { iceServers: [{ urls: [...], username, credential }] }
                    // Pass through directly — includes STUN + TURN in one entry
                    if (! empty($data['iceServers'])) {
                        $iceServers = $this->normalizeIceServersForBrowser($data['iceServers']);
                    }
                }
            } catch (\Exception $e) {
                // Log but don't fail — STUN-only fallback
                Log::warning('Failed to fetch TURN credentials from Cloudflare', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ice_servers' => $iceServers,
        ]);
    }

    private function normalizeIceServersForBrowser(array $iceServers): array
    {
        if (! (bool) config('services.cloudflare.turn_filter_blocked_browser_ports', true)) {
            return $iceServers;
        }

        $normalized = [];
        foreach ($iceServers as $server) {
            $urls = $server['urls'] ?? [];
            $urlList = is_array($urls) ? $urls : [$urls];
            $urlList = array_values(array_filter($urlList, fn ($url) => ! preg_match('/:53(?:\\?|$)/', (string) $url)));

            if (count($urlList) === 0) {
                continue;
            }

            $entry = $server;
            $entry['urls'] = count($urlList) === 1 ? $urlList[0] : $urlList;
            $normalized[] = $entry;
        }

        return count($normalized) > 0
            ? $normalized
            : [['urls' => 'stun:stun.cloudflare.com:3478']];
    }

    /**
     * Initiate a call — notifies the other participant(s).
     */
    public function initiate(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_type' => 'required|in:video,audio',
        ]);

        $user = Auth::user();

        // Concurrency Check: Prevent Split-Brain
        // Check if there is already an active call for this chat
        $key = "chat:active_call:{$chat->public_id}";
        $existingCallId = \Illuminate\Support\Facades\Cache::get($key);

        if ($existingCallId) {
            // Self-Cleaning check: If current user is in the existing call,
            // OR if all participants are offline, we can clean and take over.
            $participants = $this->getParticipantsList($chat->public_id, $existingCallId);
            $presenceService = app(\App\Services\Chat\PresenceService::class);

            $othersOnline = false;
            foreach ($participants as $p) {
                if ($p['public_id'] !== $user->public_id && $presenceService->isUserActive(User::where('public_id', $p['public_id'])->first()?->id ?? 0)) {
                    $othersOnline = true;
                    break;
                }
            }

            if (! $othersOnline) {
                // Clear the stale/ghost lock
                \Illuminate\Support\Facades\Cache::forget($key);
                Log::info("Cleaning up stale/ghost call lock for chat {$chat->public_id}");
            } else {
                return response()->json([
                    'error' => 'Call already active in this chat.',
                    'code' => 'CALL_ALREADY_ACTIVE',
                    'call_id' => $existingCallId,
                ], 409);
            }
        }

        $callId = (string) Str::ulid();

        // Check which participants are busy/offline before broadcasting
        $presenceService = app(\App\Services\Chat\PresenceService::class);
        $busyParticipants = [];
        $offlineParticipants = [];

        foreach ($chat->participants as $participant) {
            if ($participant->public_id === $user->public_id) {
                continue;
            }
            $status = $presenceService->presenceStatus($participant->id);
            if ($status === 'busy') {
                $busyParticipants[] = $participant->name;
            } elseif ($status === 'offline') {
                $offlineParticipants[] = $participant->name;
            }
        }

        // Store call metadata
        $this->storeCallMetadata($chat->public_id, $callId, [
            'type' => $request->input('call_type'),
            'initiator_id' => $user->public_id,
            'initiator_name' => $user->name,
            'started_at' => now()->timestamp,
        ]);
        $this->createCallSession($chat, $callId, $user, $request->input('call_type'));

        // Register initiator as the first participant
        $this->addParticipant($chat->public_id, $callId, $user);

        event(new CallInitiated($chat, $user, $callId, $request->input('call_type')));

        // Set active call pointer for the chat (Short Lease: 3 mins)
        $key = "chat:active_call:{$chat->public_id}";
        \Illuminate\Support\Facades\Cache::put($key, $callId, 180);

        return response()->json([
            'status' => 'ok',
            'call_id' => $callId,
            'busy_participants' => $busyParticipants,
            'offline_participants' => $offlineParticipants,
        ]);
    }

    protected function logCallEvent(Chat $chat, string $callId, string $event, array $data = []): void
    {
        $chat->messages()->create([
            'type' => 'system',
            'content' => '', // Content determined by frontend using metadata
            'metadata' => array_merge($data, [
                'system_type' => 'call_event',
                'call_id' => $callId,
                'event' => $event,
            ]),
            'user_id' => auth()->id() ?? User::where('public_id', $chat->participants->first()->public_id)->first()?->id,
        ]);
    }

    protected function createCallSession(Chat $chat, string $callId, User $initiator, string $callType): void
    {
        CallSession::query()->updateOrCreate(
            ['call_id' => $callId],
            [
                'chat_id' => $chat->id,
                'initiator_user_id' => $initiator->id,
                'call_type' => $callType,
                'status' => 'ringing',
                'metadata' => [
                    'chat_public_id' => $chat->public_id,
                    'initiator_public_id' => $initiator->public_id,
                    'initiator_name' => $initiator->name,
                ],
            ],
        );
    }

    protected function getOrCreateCallSession(Chat $chat, string $callId, array $metadata): CallSession
    {
        $session = CallSession::query()->firstOrNew(['call_id' => $callId]);

        if (! $session->exists) {
            $session->chat_id = $chat->id;
            $session->call_type = (string) ($metadata['type'] ?? 'video');
            $initiatorPublicId = (string) ($metadata['initiator_id'] ?? '');
            $session->initiator_user_id = $initiatorPublicId !== ''
                ? User::where('public_id', $initiatorPublicId)->value('id')
                : null;
            $session->status = 'ringing';
            $session->metadata = ['chat_public_id' => $chat->public_id];
            $session->save();

            return $session;
        }

        $dirty = false;

        if (! $session->chat_id) {
            $session->chat_id = $chat->id;
            $dirty = true;
        }
        if (! $session->call_type) {
            $session->call_type = (string) ($metadata['type'] ?? 'video');
            $dirty = true;
        }
        if (! $session->initiator_user_id && ! empty($metadata['initiator_id'])) {
            $session->initiator_user_id = User::where('public_id', (string) $metadata['initiator_id'])->value('id');
            $dirty = true;
        }

        if ($dirty) {
            $session->save();
        }

        return $session;
    }

    protected function hydrateMetadataFromSession(array $metadata, CallSession $callSession): array
    {
        $callSession->loadMissing(['initiator', 'answeredBy']);

        if (! isset($metadata['type']) || $metadata['type'] === '') {
            $metadata['type'] = $callSession->call_type ?: 'video';
        }

        if (
            (! isset($metadata['initiator_id']) || $metadata['initiator_id'] === '')
            && $callSession->initiator?->public_id
        ) {
            $metadata['initiator_id'] = $callSession->initiator->public_id;
        }

        if (
            (! isset($metadata['initiator_name']) || $metadata['initiator_name'] === '')
            && $callSession->initiator?->name
        ) {
            $metadata['initiator_name'] = $callSession->initiator->name;
        }

        if (! isset($metadata['answered_at']) && $callSession->answered_at) {
            $metadata['answered_at'] = $callSession->answered_at->timestamp;
        }

        if (
            (! isset($metadata['answered_by_public_id']) || $metadata['answered_by_public_id'] === '')
            && $callSession->answeredBy?->public_id
        ) {
            $metadata['answered_by_public_id'] = $callSession->answeredBy->public_id;
        }

        return $metadata;
    }

    protected function shouldFinalizeCall(Chat $chat, CallSession $callSession, string $reason, array $remainingParticipants): bool
    {
        if (in_array($reason, ['declined', 'busy', 'no_answer', 'timeout', 'failed'], true)) {
            return true;
        }

        $remainingCount = count($remainingParticipants);
        $isDm = ($chat->type ?? 'dm') === 'dm';

        if ($callSession->answered_at) {
            return $isDm ? $remainingCount < 2 : $remainingCount === 0;
        }

        return $remainingCount === 0;
    }

    protected function terminalStatusForReason(CallSession $callSession, string $reason): string
    {
        if ($callSession->answered_at) {
            return $reason === 'failed' ? 'failed' : 'ended';
        }

        return match ($reason) {
            'no_answer', 'timeout' => 'missed',
            'declined' => 'declined',
            'hangup' => 'cancelled',
            'busy' => 'busy',
            'failed' => 'failed',
            default => 'ended',
        };
    }

    protected function maybeMarkCallAnswered(Chat $chat, string $callId, array $metadata, User $user): array
    {
        $callSession = $this->getOrCreateCallSession($chat, $callId, $metadata);
        $metadata = $this->hydrateMetadataFromSession($metadata, $callSession);
        $initiatorId = (string) ($metadata['initiator_id'] ?? '');
        if ($callSession->answered_at || $initiatorId === '' || $user->public_id === $initiatorId) {
            return $metadata;
        }

        $answeredAt = now();
        $claimed = CallSession::query()
            ->whereKey($callSession->id)
            ->whereNull('answered_at')
            ->where(function ($query) use ($user) {
                $query->whereNull('initiator_user_id')
                    ->orWhere('initiator_user_id', '!=', $user->id);
            })
            ->update([
                'status' => 'connected',
                'answered_at' => $answeredAt,
                'answered_by_user_id' => $user->id,
                'updated_at' => $answeredAt,
            ]);

        if ($claimed === 0) {
            return $metadata;
        }

        $metadata['answered_at'] = $answeredAt->timestamp;
        $metadata['answered_by_public_id'] = $user->public_id;
        $metadata['answered_by_name'] = $user->name;
        $this->storeCallMetadata($chat->public_id, $callId, $metadata);

        $this->logCallEvent($chat, $callId, 'started', [
            'type' => $metadata['type'] ?? 'video',
            'user_name' => (string) ($metadata['initiator_name'] ?? 'Someone'),
        ]);

        return $metadata;
    }

    protected function resolveTerminalCallLog(Chat $chat, CallSession $callSession, array $metadata, string $reason): ?array
    {
        $metadata = $this->hydrateMetadataFromSession($metadata, $callSession);

        if ($callSession->answered_at) {
            return [
                'event' => 'ended',
                'data' => [
                    'duration' => max(0, $callSession->ended_at?->timestamp - $callSession->answered_at->timestamp),
                    'type' => $metadata['type'] ?? 'video',
                ],
            ];
        }

        $callerPublicId = $metadata['initiator_id'] ?? null;
        $callerName = (string) ($metadata['initiator_name'] ?? '');
        if ($callerName === '' && $callerPublicId) {
            $callerName = User::where('public_id', $callerPublicId)->first()?->name ?? 'Unknown';
        }

        $baseData = [
            'type' => $metadata['type'] ?? 'video',
            'chat_id' => $chat->public_id,
            'caller_public_id' => $callerPublicId,
            'caller_name' => $callerName !== '' ? $callerName : 'Unknown',
        ];

        return match ($reason) {
            'no_answer', 'timeout' => ['event' => 'missed', 'data' => $baseData],
            'declined' => ['event' => 'declined', 'data' => $baseData],
            'hangup' => ['event' => 'cancelled', 'data' => $baseData],
            default => null,
        };
    }

    /**
     * Join an existing call.
     */
    public function join(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|regex:/^[0-9A-Z]{26}$/|max:26',
        ]);

        $user = Auth::user();
        $callId = $request->input('call_id');

        // Sec 1: Verify call_id matches the active call for this chat
        // This prevents hijacking by injecting an arbitrary call_id
        $key = "chat:active_call:{$chat->public_id}";
        $activeCallId = \Illuminate\Support\Facades\Cache::get($key);

        if (! $activeCallId || $activeCallId !== $callId) {
            // Fallback: If cache expired but call might still be valid?
            // Strictly speaking, if it's not the active call, we shouldn't allow joining via this route
            // unless we want to allow joining "old" calls (which we don't, they are ephemeral).
            abort(404, 'Call not found or inactive.');
        }

        // Add to cache
        $this->addParticipant($chat->public_id, $callId, $user);

        // Broadcast join event so existing peers can connect
        event(new \App\Events\Chat\CallParticipantJoined(
            $chat->public_id,
            $chat->type ?? 'dm',
            $callId,
            $user->public_id,
            $user->name,
            $user->avatar_thumb_url
        ));

        // Return current participants so the joiner can connect to them
        $participants = $this->getParticipantsList($chat->public_id, $callId);
        $metadata = $this->getCallMetadata($chat->public_id, $callId);
        $metadata = $this->maybeMarkCallAnswered($chat, $callId, $metadata, $user);

        // FORCE SFU LOGIC: All calls (both 1:1 and group) now use Cloudflare SFU.
        // This removes the legacy 1:1 P2P mesh logic.
        $mode = 'sfu';

        return response()->json([
            'status' => 'ok',
            'participants' => $participants,
            'type' => $metadata['type'] ?? 'video',
            'mode' => $mode,
            'app_id' => config('services.cloudflare.app_id'), // Share public AppID if SFU
        ]);
    }

    /**
     * Refresh the call lease/lock (Heartbeat).
     */
    public function heartbeat(Request $request, Chat $chat): JsonResponse
    {
        $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|regex:/^[0-9A-Z]{26}$/|max:26',
        ]);

        $callId = $request->input('call_id');
        $key = "chat:active_call:{$chat->public_id}";
        $activeCallId = \Illuminate\Support\Facades\Cache::get($key);

        if ($activeCallId === $callId) {
            // Refresh Lease (3 mins)
            \Illuminate\Support\Facades\Cache::put($key, $callId, 180);

            // Also refresh meta and participants to keep everything in sync
            $metaKey = "call:meta:{$chat->public_id}:{$callId}";
            $partKey = $this->getCacheKey($chat->public_id, $callId);

            if ($meta = \Illuminate\Support\Facades\Cache::get($metaKey)) {
                \Illuminate\Support\Facades\Cache::put($metaKey, $meta, 180);
            }
            if ($parts = \Illuminate\Support\Facades\Cache::get($partKey)) {
                \Illuminate\Support\Facades\Cache::put($partKey, $parts, 180);
            }

            return response()->json(['status' => 'ok', 'refreshed' => true]);
        }

        return response()->json(['status' => 'inactive', 'refreshed' => false], 410);
    }

    /**
     * Check if there is an active call in the chat.
     */
    public function active(Request $request, Chat $chat): JsonResponse
    {
        $this->findChatOrFail($chat);

        $key = "chat:active_call:{$chat->public_id}";
        $callId = \Illuminate\Support\Facades\Cache::get($key);

        if (! $callId) {
            return response()->json(['active' => false]);
        }

        $metadata = $this->getCallMetadata($chat->public_id, $callId);
        $participants = $this->getParticipantsList($chat->public_id, $callId);

        // Presence-aware cleanup if we have a lock but no actual activity
        // If it's looking stale, we try to clear it
        if (empty($participants)) {
            $startedAt = $metadata['started_at'] ?? 0;
            if (now()->timestamp - $startedAt > 120) { // 2 mins idle with no participants
                \Illuminate\Support\Facades\Cache::forget($key);

                return response()->json(['active' => false]);
            }
        }

        return response()->json([
            'active' => true,
            'call_id' => $callId,
            'type' => $metadata['type'] ?? 'video',
            'initiator_id' => $metadata['initiator_id'] ?? null,
            'participants' => $participants,
            'app_id' => config('services.cloudflare.app_id'),
        ]);
    }

    /**
     * SFU PROXY: New Session
     */
    public function sfuSessionNew(Request $request, Chat $chat): JsonResponse
    {
        $request->validate([
            'sessionDescription' => 'required|array',
            'sessionDescription.sdp' => 'required|string',
        ]);

        $sdp = $request->input('sessionDescription.sdp', '');
        $lastChars = substr($sdp, -10);

        Log::channel('videocall')->info('[SFU] sfuSessionNew called', [
            'chat_id' => $chat->id,
            'public_id' => $chat->public_id,
            'sdp_length' => strlen($sdp),
            'last_chars_hex' => $lastChars ? bin2hex($lastChars) : '',
        ]);

        $this->findChatOrFail($chat);
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        if (! $appId || ! $secret) {
            return response()->json(['error' => 'SFU not configured'], 503);
        }

        Log::channel('videocall')->info('[SFU] sfuSessionNew details', [
            'appId' => $appId,
            'url' => "https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/new",
        ]);

        // Only forward Cloudflare-relevant fields (exclude internal fields like call_id)
        $cfPayload = $request->only(['sessionDescription', 'tracks']);

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/new", $cfPayload);

            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Cloudflare session/new error:', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error('[SFU] Cloudflare session/new exception:', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'SFU Session Creation Timeout/Error', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * SFU PROXY: New Tracks
     */
    public function sfuSessionTracks(Request $request, Chat $chat, string $sessionId): JsonResponse
    {
        Log::channel('videocall')->info('[SFU] sfuSessionTracks called', [
            'chat_id' => $chat->id,
            'session_id' => $sessionId,
            'tracks_count' => count($request->input('tracks', [])),
            'sdp_length' => strlen($request->input('sessionDescription.sdp', '')),
        ]);

        $this->findChatOrFail($chat);
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/tracks/new", $request->only(['sessionDescription', 'tracks']));

            $responseData = $response->json();
            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Cloudflare tracks/new error:', [
                    'status' => $response->status(),
                    'body' => $responseData ?: $response->body(),
                    'request_payload' => $request->only(['sessionDescription', 'tracks']),
                ]);
            } else {
                Log::channel('videocall')->info('[SFU] Cloudflare tracks/new success', [
                    'status' => $response->status(),
                    'response' => $responseData,
                ]);
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error('[SFU] Cloudflare tracks/new exception:', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);

            return response()->json(['error' => 'SFU Track Pull Timeout/Error', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * SFU PROXY: Renegotiate
     */
    public function sfuSessionRenegotiate(Request $request, Chat $chat, string $sessionId): JsonResponse
    {
        $this->findChatOrFail($chat);
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        $method = strtolower($request->method());
        $data = $request->all();

        // Fix: Preserve empty string for rollback or pull-offer SDP if it was sent as empty
        // This prevents Laravel middleware from converting it to null, which Cloudflare rejects.
        if (isset($data['sessionDescription']['type']) &&
            ($data['sessionDescription']['type'] === 'rollback' || $data['sessionDescription']['type'] === 'offer') &&
            array_key_exists('sdp', $data['sessionDescription']) &&
            $data['sessionDescription']['sdp'] === null) {
            $data['sessionDescription']['sdp'] = '';
        }

        Log::channel('videocall')->info("[SFU] Renegotiating session {$sessionId}", [
            'method' => $method,
            'data' => $data,
        ]);

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->send($method, "https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/renegotiate", [
                    'json' => ! empty($data) ? $data : null,
                ]);

            $responseData = $response->json();
            if (! $response->successful()) {
                $errorData = $responseData;
                Log::channel('videocall')->error('[SFU] Cloudflare renegotiate error:', [
                    'status' => $response->status(),
                    'body' => $errorData ?: $response->body(),
                ]);

                return response()->json([
                    'error' => 'Cloudflare Renegotiation Error',
                    'status' => $response->status(),
                    'details' => $errorData ?: $response->body(),
                ], $response->status());
            }

            Log::channel('videocall')->info('[SFU] Cloudflare renegotiate success', [
                'status' => $response->status(),
                'response' => $responseData,
            ]);

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error("[SFU] Cloudflare renegotiate exception: {$e->getMessage()}");

            return response()->json([
                'error' => 'SFU Renegotiation Proxy Exception',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get list of current participants.
     */
    public function participants(Request $request, Chat $chat, string $callId): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);
        $participants = $this->getParticipantsList($chat->public_id, $callId);
        $metadata = $this->getCallMetadata($chat->public_id, $callId);

        return response()->json([
            'participants' => $participants,
            'type' => $metadata['type'] ?? 'video',
        ]);
    }

    /**
     * Relay a WebRTC signal (offer, answer, or ICE candidate).
     */
    public function signal(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|regex:/^[0-9A-Z]{26}$/|max:26',
            'signal_type' => 'required|in:offer,answer,ice-candidate,signal',
            'signal_data' => 'required|array|max:100',
            'signal_data.type' => 'sometimes|string|max:64',
            'signal_data.sdp' => 'sometimes|string|max:50000',
            'target_public_id' => 'nullable|string',
        ]);

        // Sec 5: Validate target participant exists if specified
        if ($request->filled('target_public_id')) {
            $participants = $this->getParticipantsList($chat->public_id, $request->input('call_id'));
            $targetExists = collect($participants)->contains('public_id', $request->input('target_public_id'));

            if (! $targetExists) {
                // Return 422 to let client know target is gone (prevents retry loops)
                abort(422, 'Target participant not found in call.');
            }
        }

        $user = Auth::user();

        $callSignalEvent = new CallSignal(
            $chat,
            $user->public_id,
            $request->input('call_id'),
            $request->input('signal_type'),
            $request->input('signal_data'),
            $request->input('target_public_id')
        );
        $callSignalEvent->dontBroadcastToCurrentUser();
        event($callSignalEvent);

        return response()->json(['status' => 'ok']);
    }

    /**
     * End an active call or leave it.
     */
    public function end(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|regex:/^[0-9A-Z]{26}$/|max:26',
            'reason' => 'sometimes|in:hangup,declined,timeout,failed,no_answer,busy',
        ]);

        $user = Auth::user();
        $callId = $request->input('call_id');
        $reason = $request->input('reason', 'hangup');

        // Sec 2: Verify user is actually a participant
        // Allow unanswered invite responses before the user has joined the call.
        $participants = $this->getParticipantsList($chat->public_id, $callId);
        $isInCall = collect($participants)->contains('public_id', $user->public_id);

        if (! $isInCall && ! in_array($reason, ['no_answer', 'busy', 'declined'])) {
            abort(403, 'You are not in this call.');
        }

        $metadata = $this->getCallMetadata($chat->public_id, $callId);
        $callSession = $this->getOrCreateCallSession($chat, $callId, $metadata);
        $metadata = $this->hydrateMetadataFromSession($metadata, $callSession);
        if (($metadata['finalized_at'] ?? null) !== null) {
            return response()->json(['status' => 'ok', 'already_finalized' => true]);
        }
        if ($callSession->finalized_at !== null) {
            return response()->json(['status' => 'ok', 'already_finalized' => true]);
        }

        // Remove from cache
        $this->removeParticipant($chat->public_id, $callId, $user->public_id);

        // Notify others that this user left
        event(new \App\Events\Chat\CallParticipantLeft(
            $chat->public_id,
            $chat->type ?? 'dm',
            $callId,
            $user->public_id,
            $reason
        ));

        // Check if call should be ended globally
        $participants = $this->getParticipantsList($chat->public_id, $callId);
        $callSession->refresh();
        $metadata = $this->hydrateMetadataFromSession($metadata, $callSession);
        $shouldFinalize = $this->shouldFinalizeCall($chat, $callSession, $reason, $participants);

        if ($shouldFinalize) {
            $finalizedAt = now();
            $status = $this->terminalStatusForReason($callSession, $reason);
            $claimed = CallSession::query()
                ->whereKey($callSession->id)
                ->whereNull('finalized_at')
                ->update([
                    'status' => $status,
                    'ended_at' => $finalizedAt,
                    'end_reason' => $reason,
                    'finalized_at' => $finalizedAt,
                    'updated_at' => $finalizedAt,
                ]);

            if ($claimed === 0) {
                return response()->json(['status' => 'ok', 'already_finalized' => true]);
            }

            $callSession->refresh();
            $metadata['finalized_at'] = $finalizedAt->timestamp;
            $metadata['final_reason'] = $reason;
            $this->storeCallMetadata($chat->public_id, $callId, $metadata);

            $terminalLog = $this->resolveTerminalCallLog($chat, $callSession, $metadata, $reason);
            if ($terminalLog) {
                $this->logCallEvent(
                    $chat,
                    $callId,
                    $terminalLog['event'],
                    $terminalLog['data'],
                );
            }

            // Missed Call Notifications
            if (in_array($reason, ['no_answer', 'timeout', 'declined', 'busy'])) {
                $callerId = $metadata['initiator_id'] ?? null;
                $callerName = $metadata['initiator_name'] ?? 'Someone';
                $callType = $metadata['type'] ?? 'video';

                Log::channel('videocall')->info('[NOTIFICATION] Processing missed call notifications', [
                    'call_id' => $callId,
                    'reason' => $reason,
                    'caller' => $callerId
                ]);

                foreach ($chat->participants as $participant) {
                    // Notify everyone except the initiator and anyone who actually JOINED the call.
                    // (remainingParticipants list passed in is not enough as we need all intended recipients)
                    if ($participant->public_id !== $callerId) {
                        $pKey = $this->getCacheKey($chat->public_id, $callId);
                        $currentParticipants = \Illuminate\Support\Facades\Cache::get($pKey, []);
                        $hasJoined = collect($currentParticipants)->contains('public_id', $participant->public_id);

                        if (!$hasJoined) {
                            Log::channel('videocall')->info('[NOTIFICATION] Dispatching MissedCallNotification', [
                                'to' => $participant->public_id,
                                'caller' => $callerName
                            ]);
                            $participant->notify(new MissedCallNotification(
                                $callerName,
                                $callType,
                                $chat->public_id,
                                $chat->name ?? ''
                            ));
                        }
                    }
                }
            }

            // Broadcast end
            event(new CallEnded($chat, $user->public_id, $callId, $reason));

            // Clear active call pointer if it matches this call
            $key = "chat:active_call:{$chat->public_id}";
            if (\Illuminate\Support\Facades\Cache::get($key) === $callId) {
                \Illuminate\Support\Facades\Cache::forget($key);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    // =========================================================================
    // Cache Helpers (Redis/File)
    // =========================================================================

    private function getCacheKey(string $chatId, string $callId): string
    {
        return "call:participants:{$chatId}:{$callId}";
    }

    private function addParticipant(string $chatId, string $callId, $user): void
    {
        $key = $this->getCacheKey($chatId, $callId);
        $participant = [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'avatar' => $user->avatar_thumb_url,
            'joined_at' => now()->timestamp,
        ];

        // Use a simple array in cache for now. Ideally this would be a Redis Set.
        $participants = \Illuminate\Support\Facades\Cache::get($key, []);

        // Remove existing if present (update)
        $participants = array_filter($participants, fn ($p) => $p['public_id'] !== $user->public_id);
        $participants[] = $participant;

        // Expire after 3 minutes to clean up stale calls
        \Illuminate\Support\Facades\Cache::put($key, $participants, 180);
    }

    private function removeParticipant(string $chatId, string $callId, string $userPublicId): void
    {
        $key = $this->getCacheKey($chatId, $callId);
        $participants = \Illuminate\Support\Facades\Cache::get($key, []);

        $participants = array_filter($participants, fn ($p) => $p['public_id'] !== $userPublicId);

        if (empty($participants)) {
            \Illuminate\Support\Facades\Cache::forget($key);
        } else {
            \Illuminate\Support\Facades\Cache::put($key, $participants, 180);
        }
    }

    private function getParticipantsList(string $chatId, string $callId): array
    {
        $key = $this->getCacheKey($chatId, $callId);

        return array_values(\Illuminate\Support\Facades\Cache::get($key, []));
    }

    private function storeCallMetadata(string $chatId, string $callId, array $metadata): void
    {
        $key = "call:meta:{$chatId}:{$callId}";
        \Illuminate\Support\Facades\Cache::put($key, $metadata, 180);
    }

    private function getCallMetadata(string $chatId, string $callId): array
    {
        $key = "call:meta:{$chatId}:{$callId}";

        return \Illuminate\Support\Facades\Cache::get($key, []);
    }
}
