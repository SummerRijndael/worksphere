<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\CallEnded;
use App\Events\Chat\CallInitiated;
use App\Events\Chat\CallSignal;
use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::withToken($turnApiToken)
                    ->post("https://rtc.live.cloudflare.com/v1/turn/keys/{$turnKeyId}/credentials/generate-ice-servers", [
                        'ttl' => 86400, // 24 hours
                    ]);

                if ($response->successful()) {
                    $data = $response->json();

                    // Cloudflare returns { iceServers: [{ urls: [...], username, credential }] }
                    // Pass through directly — includes STUN + TURN in one entry
                    if (! empty($data['iceServers'])) {
                        $iceServers = $data['iceServers'];
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
        $callId = (string) Str::ulid();

        // Store call metadata
        $this->storeCallMetadata($chat->public_id, $callId, [
            'type' => $request->input('call_type'),
            'initiator_id' => $user->public_id,
            'started_at' => now()->timestamp,
        ]);

        // Register initiator as the first participant
        $this->addParticipant($chat->public_id, $callId, $user);

        event(new CallInitiated($chat, $user, $callId, $request->input('call_type')));

        // Log call start as system message
        $this->logCallEvent($chat, $callId, 'started', [
            'type' => $request->input('call_type'),
            'user_name' => auth()->user()->name,
        ]);

        // Set active call pointer for the chat
        // This allows later joiners to find the current active call ID
        $key = "chat:active_call:{$chat->public_id}";
        \Illuminate\Support\Facades\Cache::put($key, $callId, 7200); // 2 hours TTL matches participants TTL

        return response()->json([
            'status' => 'ok',
            'call_id' => $callId,
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

    /**
     * Join an existing call.
     */
    public function join(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|max:64',
        ]);

        $user = Auth::user();
        $callId = $request->input('call_id');

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

        // HYBRID LOGIC: Force SFU for group chats OR if total participants > 2.
        // This ensures group conversations start on Cloudflare immediately,
        // preventing Mesh-to-SFU transition edge cases for existing participants.
        $mode = ($chat->type === 'group' || count($participants) > 2) ? 'sfu' : 'mesh';

        return response()->json([
            'status' => 'ok',
            'participants' => $participants,
            'type' => $metadata['type'] ?? 'video',
            'mode' => $mode,
            'app_id' => config('services.cloudflare.app_id'), // Share public AppID if SFU
        ]);
    }

    /**
     * Check if there is an active call in the chat.
     */
    public function active(Request $request, Chat $chat): JsonResponse
    {
        $this->findChatOrFail($chat);

        // We don't have a direct "Call ID" index, so we need to rely on the frontend
        // asking "Is there a call?".
        // However, our cache keys are `call:participants:{chatId}:{callId}`.
        // We can scan for keys matching this pattern. 
        // NOTE: excessive scanning is bad for Redis performance in production, 
        // but for now with `file` or `redis` cache driver in a smaller app, it might be okay.
        // BETTER APPROACH: Store a "current_call:{chatId}" key when a call starts.

        // Let's check if we can leverage existing metadata.
        // For now, I will implement a "current_call" pointer in cache to make this efficient.
        // But I need to update `initiate` to set this.
        
        // Revised Plan:
        // 1. `initiate` sets `chat:active_call:{chatId}` -> `callId`
        // 2. `end` removes it if it's the same callId.
        // 3. `active` reads this key.

        $key = "chat:active_call:{$chat->public_id}";
        $callId = \Illuminate\Support\Facades\Cache::get($key);

        if (!$callId) {
            return response()->json(['active' => false]);
        }

        $metadata = $this->getCallMetadata($chat->public_id, $callId);
        $participants = $this->getParticipantsList($chat->public_id, $callId);

        // Double check if call is actually valid (has participants or just started)
        // If no participants and started > 5 mins ago, consider it stale/dead.
        if (empty($participants)) {
             $startedAt = $metadata['started_at'] ?? 0;
             if (now()->timestamp - $startedAt > 300) {
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
        $sdp = $request->input('sessionDescription.sdp', '');
        $lastChars = substr($sdp, -10);
        Log::channel('videocall')->info("[SFU] sfuSessionNew called", [
            'chat_id' => $chat->id,
            'public_id' => $chat->public_id,
            'sdp_length' => strlen($sdp),
            'last_chars_hex' => $lastChars ? bin2hex($lastChars) : '',
        ]);

        $this->findChatOrFail($chat);
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        Log::channel('videocall')->info("[SFU] sfuSessionNew details", [
            'appId' => $appId,
            'secret_prefix' => substr($secret, 0, 5) . '...',
            'url' => "https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/new"
        ]);

        if (!$appId || !$secret) {
            return response()->json(['error' => 'SFU not configured'], 503);
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/new", $request->all());

            if (!$response->successful()) {
                Log::channel('videocall')->error("[SFU] Cloudflare session/new error:", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error("[SFU] Cloudflare session/new exception:", [
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'SFU Session Creation Timeout/Error', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * SFU PROXY: New Tracks
     */
    public function sfuSessionTracks(Request $request, Chat $chat, string $sessionId): JsonResponse
    {
        Log::channel('videocall')->info("[SFU] sfuSessionTracks called", [
            'chat_id' => $chat->id,
            'session_id' => $sessionId,
            'tracks_count' => count($request->input('tracks', [])),
            'sdp_length' => strlen($request->input('sessionDescription.sdp', ''))
        ]);

        $this->findChatOrFail($chat);
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/tracks/new", $request->all());

            $responseData = $response->json();
            if (!$response->successful()) {
                Log::channel('videocall')->error("[SFU] Cloudflare tracks/new error:", [
                    'status' => $response->status(),
                    'body' => $responseData ?: $response->body()
                ]);
            } else {
                Log::channel('videocall')->info("[SFU] Cloudflare tracks/new success", [
                    'status' => $response->status(),
                    'response' => $responseData
                ]);
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error("[SFU] Cloudflare tracks/new exception:", [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500)
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
            'data' => $data
        ]);

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->send($method, "https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/renegotiate", [
                    'json' => !empty($data) ? $data : null
                ]);

            $responseData = $response->json();
            if (!$response->successful()) {
                $errorData = $responseData;
                Log::channel('videocall')->error("[SFU] Cloudflare renegotiate error:", [
                    'status' => $response->status(),
                    'body' => $errorData ?: $response->body()
                ]);

                return response()->json([
                    'error' => 'Cloudflare Renegotiation Error',
                    'status' => $response->status(),
                    'details' => $errorData ?: $response->body()
                ], $response->status());
            }

            Log::channel('videocall')->info("[SFU] Cloudflare renegotiate success", [
                'status' => $response->status(),
                'response' => $responseData
            ]);

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error("[SFU] Cloudflare renegotiate exception: {$e->getMessage()}");
            return response()->json([
                'error' => 'SFU Renegotiation Proxy Exception',
                'details' => $e->getMessage()
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
            'call_id' => 'required|string|max:64',
            'signal_type' => 'required|in:offer,answer,ice-candidate,signal',
            'signal_data' => 'required|array',
            'target_public_id' => 'nullable|string',
        ]);

        $user = Auth::user();

        event(new CallSignal(
            $chat,
            $user->public_id,
            $request->input('call_id'),
            $request->input('signal_type'),
            $request->input('signal_data'),
            $request->input('target_public_id')
        ));

        return response()->json(['status' => 'ok']);
    }

    /**
     * End an active call or leave it.
     */
    public function end(Request $request, Chat $chat): JsonResponse
    {
        $chat = $this->findChatOrFail($chat);

        $request->validate([
            'call_id' => 'required|string|max:64',
            'reason' => 'sometimes|in:hangup,declined,timeout,failed',
        ]);

        $user = Auth::user();
        $callId = $request->input('call_id');
        $reason = $request->input('reason', 'hangup');

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
        $isLastPerson = empty($participants);

        if ($isLastPerson || $chat->type === 'dm') {
             // Calculate duration
             $metadata = $this->getCallMetadata($chat->public_id, $callId);
             $duration = 0;
             if (isset($metadata['started_at'])) {
                 $duration = now()->timestamp - $metadata['started_at'];
             }

             // Log event
             $this->logCallEvent($chat, $callId, 'ended', [
                 'duration' => $duration,
                 'user_name' => $user->name,
             ]);

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
        $participants = array_filter($participants, fn($p) => $p['public_id'] !== $user->public_id);
        $participants[] = $participant;

        // Expire after 2 hours to clean up stale calls
        \Illuminate\Support\Facades\Cache::put($key, $participants, 7200);
    }

    private function removeParticipant(string $chatId, string $callId, string $userPublicId): void
    {
        $key = $this->getCacheKey($chatId, $callId);
        $participants = \Illuminate\Support\Facades\Cache::get($key, []);
        
        $participants = array_filter($participants, fn($p) => $p['public_id'] !== $userPublicId);
        
        if (empty($participants)) {
            \Illuminate\Support\Facades\Cache::forget($key);
        } else {
            \Illuminate\Support\Facades\Cache::put($key, $participants, 7200);
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
        \Illuminate\Support\Facades\Cache::put($key, $metadata, 7200);
    }

    private function getCallMetadata(string $chatId, string $callId): array
    {
        $key = "call:meta:{$chatId}:{$callId}";
        return \Illuminate\Support\Facades\Cache::get($key, []);
    }
}
