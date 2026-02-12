<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\Chat\CallEnded;
use App\Events\Chat\CallInitiated;
use App\Events\Chat\CallSignal;
use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
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
            'user_id' => auth()->id(),
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

            if (!$response->successful()) {
                Log::channel('videocall')->error("[SFU] Cloudflare tracks/new error:", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error("[SFU] Cloudflare tracks/new exception:", [
                'error' => $e->getMessage()
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

        $response = Http::withToken($secret)
            ->put("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/renegotiate", $request->all());

        if (!$response->successful()) {
            Log::channel('videocall')->error("[SFU] Cloudflare renegotiate error:", [
                'status' => $response->status(),
                'body' => $response->json()
            ]);
        }

        return response()->json($response->json(), $response->status());
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

        // If no participants left, the call is technically over, 
        // but we assume the frontend handles the "last person leaving" logic via the events.
        // We could explicitly check `count($participants) === 0` here if we wanted to trigger a CallEnded event.
        // For backwards compatibility, if it's a DM and someone hangs up, we can still send CallEnded.
        if ($chat->type === 'dm') {
             event(new CallEnded($chat, $user->public_id, $callId, $reason));
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
