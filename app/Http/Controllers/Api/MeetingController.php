<?php

namespace App\Http\Controllers\Api;

use App\Events\Meetings\MeetingParticipantAdmitted;
use App\Events\Meetings\MeetingParticipantJoined;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MeetingController extends Controller
{
    /**
     * List user's meetings.
     */
    public function index(): AnonymousResourceCollection
    {
        $meetings = Meeting::where(function ($query) {
            $query->where('user_id', Auth::id())
                ->orWhereHas('participants', function ($query) {
                    $query->where('user_id', Auth::id());
                });
        })
        ->with(['host', 'participants.user'])
        ->orderBy('start_time', 'asc')
        ->get();

        return MeetingResource::collection($meetings);
    }

    /**
     * Create a new meeting.
     */
    public function store(Request $request): MeetingResource
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'settings' => 'nullable|array',
            'password' => [
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($request) {
                    if (($request->input('settings.guest_access') ?? false) && empty($value)) {
                        $fail('A password is required when guest access is enabled.');
                    }
                }
            ],
            'auto_generate_password' => 'nullable|boolean',
        ]);

        // Deduplication check: Prevent identical meetings within 1 minute
        $existing = Meeting::where('user_id', Auth::id())
            ->where('title', $request->title)
            ->where('start_time', $request->start_time)
            ->where('created_at', '>', now()->subMinute())
            ->first();

        if ($existing) {
            return new MeetingResource($existing);
        }

        $password = $request->password;
        if ($request->auto_generate_password) {
            $password = Str::random(10);
        }

        $meeting = Meeting::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => Auth::id(),
            'title' => $request->title,
            'description' => $request->description,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'status' => 'scheduled',
            'settings' => $request->settings ?? [],
            'password' => $password,
            'app_id' => 'worksphere',
        ]);

        // Host is automatically a participant
        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => Auth::id(),
            'role' => 'host',
            'status' => 'admitted',
        ]);

        return new MeetingResource($meeting->load(['host', 'participants.user']));
    }

    /**
     * Get meeting details for lobby.
     */
    public function show(Meeting $meeting): MeetingResource
    {
        $meeting->load(['host', 'participants.user']);
        
        return new MeetingResource($meeting);
    }

    /**
     * Join a meeting (or enter lobby).
     */
    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        $user = Auth::user();
        $isGuest = !$user;

        if ($isGuest && !($meeting->settings['guest_access'] ?? false)) {
            return response()->json(['message' => 'Guest access disabled for this meeting.'], 403);
        }

        // Password protection check
        if ($meeting->password && ($meeting->user_id !== Auth::id())) {
            $providedPassword = $request->input('password');
            if ($providedPassword !== $meeting->password) {
                return response()->json([
                    'message' => 'Invalid meeting password.',
                    'requires_password' => true
                ], 403);
            }
        }

        if ($isGuest) {
            $participant = MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'public_id' => (string) Str::ulid(),
                'role' => 'participant',
                'status' => ($meeting->settings['lobby_enabled'] ?? true) ? 'waiting' : 'admitted',
                'metadata' => ['guest_name' => $request->input('name', 'Guest')],
            ]);
        } else {
            $participant = MeetingParticipant::firstOrCreate(
                [
                    'meeting_id' => $meeting->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $meeting->user_id === $user->id ? 'host' : 'participant',
                    'status' => ($meeting->settings['lobby_enabled'] ?? true) ? 'waiting' : 'admitted',
                ]
            );
        }

        // Broadcast that someone has joined the lobby/meeting
        broadcast(new MeetingParticipantJoined($meeting, $participant));

        return response()->json([
            'data' => [
                'meeting' => new MeetingResource($meeting->load(['host', 'participants.user'])),
                'participant' => $participant,
            ]
        ]);
    }

    /**
     * WebRTC Signaling for Meetings.
     */
    public function signal(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate([
            'signal_type' => 'required|string',
            'signal_data' => 'present|array',
            'target_participant_public_id' => 'nullable|string',
            'sender_participant_public_id' => 'required|string',
        ]);

        // Verify sender is a participant and admitted
        $sender = MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('public_id', $request->sender_participant_public_id)
            ->where('status', 'admitted')
            ->first();

        if (!$sender) {
            return response()->json(['message' => 'Unauthorized or not admitted'], 403);
        }

        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            $sender->public_id,
            $request->signal_type,
            $request->signal_data,
            $request->target_participant_public_id
        ))->toOthers();
        
        return response()->json(['status' => 'ok']);
    }

    /**
     * Update meeting settings (Host Only).
     */
    public function update(Request $request, Meeting $meeting): MeetingResource
    {
        if ($meeting->user_id !== Auth::id()) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'settings' => 'nullable|array',
            'password' => 'nullable|string|max:100',
            'auto_generate_password' => 'nullable|boolean',
        ]);

        $data = $request->only(['title', 'description', 'start_time', 'end_time', 'status', 'settings']);

        if ($request->has('auto_generate_password') && $request->auto_generate_password) {
            $data['password'] = Str::random(10);
        } elseif ($request->has('password')) {
            $data['password'] = $request->password;
        }

        $meeting->update($data);

        return new MeetingResource($meeting->load(['host', 'participants.user']));
    }

    /**
     * Delete/Cancel a meeting.
     */
    public function destroy(Meeting $meeting): JsonResponse
    {
        // Only host can delete
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can cancel this meeting'], 403);
        }

        $meeting->delete();

        return response()->json(['message' => 'Meeting cancelled successfully']);
    }

    /**
     * Generate short-lived TURN credentials via Cloudflare API.
     * Falls back to STUN-only config if TURN is not configured.
     */
    public function turnCredentials(Meeting $meeting): JsonResponse
    {
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
                        'ttl' => 3600, // 1 hour (Cloudflare recommended max)
                    ]);

                if ($response->successful()) {
                    $data = $response->json();

                    // Cloudflare returns { iceServers: [{ urls: [...], username, credential }] }
                    if (! empty($data['iceServers'])) {
                        $iceServers = $data['iceServers'];
                    }
                }
            } catch (\Exception $e) {
                // Log but don't fail — STUN-only fallback
                Log::warning('Failed to fetch TURN credentials from Cloudflare for meeting', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ice_servers' => $iceServers,
        ]);
    }

    /**
     * SFU PROXY: New Session for Meeting
     */
    public function sfuSessionNew(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate([
            'sessionDescription' => 'required|array',
            'sessionDescription.sdp' => 'required|string',
        ]);

        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        if (! $appId || ! $secret) {
            return response()->json(['error' => 'SFU not configured'], 503);
        }

        // Only forward Cloudflare-relevant fields
        $cfPayload = $request->only(['sessionDescription', 'tracks']);

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/new", $cfPayload);

            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Meeting Cloudflare session/new error:', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            Log::channel('videocall')->error('[SFU] Meeting Cloudflare session/new exception:', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'SFU Session Creation Timeout/Error', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * SFU PROXY: New Tracks for Meeting
     */
    public function sfuSessionTracks(Request $request, Meeting $meeting, string $sessionId): JsonResponse
    {
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/tracks/new", $request->only(['sessionDescription', 'tracks']));

            $responseData = $response->json();
            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Meeting Cloudflare tracks/new error:', [
                    'status' => $response->status(),
                    'body' => $responseData ?: $response->body(),
                    'request_payload' => $request->only(['sessionDescription', 'tracks']), // Help debug 400s
                ]);
            } else {
                Log::channel('videocall')->info('[SFU] Meeting Cloudflare tracks/new success', [
                    'status' => $response->status(),
                    'response' => $responseData,
                ]);
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'SFU Track Pull Timeout/Error', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * SFU PROXY: Renegotiate for Meeting
     */
    public function sfuSessionRenegotiate(Request $request, Meeting $meeting, string $sessionId): JsonResponse
    {
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        $method = strtolower($request->method());
        $data = $request->all();

        // Preserve empty string for rollback/offer SDP if sent as empty (avoids Cloudflare reject due to null)
        if (isset($data['sessionDescription']['type']) &&
            ($data['sessionDescription']['type'] === 'rollback' || $data['sessionDescription']['type'] === 'offer') &&
            array_key_exists('sdp', $data['sessionDescription']) &&
            $data['sessionDescription']['sdp'] === null) {
            $data['sessionDescription']['sdp'] = '';
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->send($method, "https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/renegotiate", [
                    'json' => ! empty($data) ? $data : null,
                ]);

            $responseData = $response->json();
            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Meeting Cloudflare renegotiate error:', [
                    'status' => $response->status(),
                    'body' => $responseData ?: $response->body(),
                ]);
                return response()->json([
                    'error' => 'Cloudflare Renegotiation Error',
                    'status' => $response->status(),
                    'details' => $responseData ?: $response->body(),
                ], $response->status());
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'SFU Renegotiation Proxy Exception',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admit a participant from the waiting room.
     */
    public function admit(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        // Only host can admit
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can admit participants.'], 403);
        }

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }

        $participant->update(['status' => 'admitted']);

        // Broadcast admission
        broadcast(new MeetingParticipantAdmitted($meeting, $participant))->toOthers();

        return response()->json(['message' => 'Participant admitted.', 'participant' => $participant]);
    }

    /**
     * Reject a participant from the waiting room.
     */
    public function reject(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        // Only host can reject
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can reject participants.'], 403);
        }

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }

        $participant->update(['status' => 'rejected']);

        return response()->json(['message' => 'Participant rejected.']);
    }
}
