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

        $meeting = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $password) {
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

            return $meeting;
        });

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
    if ($meeting->status === 'ended') {
        return response()->json(['message' => 'This meeting has already ended.'], 403);
    }

    if ($meeting->password && ($meeting->user_id !== Auth::id())) {
            $providedPassword = $request->input('password');
            if ($providedPassword !== $meeting->password) {
                return response()->json([
                    'message' => 'Invalid meeting password.',
                    'requires_password' => true
                ], 403);
            }
        }

        // Meeting Lock check
        if ($meeting->is_locked && ($meeting->user_id !== Auth::id())) {
            // Check if they are already an admitted participant (rejoining)
            $isAlreadyIn = MeetingParticipant::where('meeting_id', $meeting->id)
                ->where('status', 'admitted')
                ->where(function ($q) use ($user) {
                    if ($user) $q->where('user_id', $user->id);
                    else $q->where('public_id', session('meeting_participant_id'));
                })->exists();

            if (!$isAlreadyIn) {
                return response()->json(['message' => 'This meeting is locked by the host.'], 403);
            }
        }

        if ($isGuest) {
            $request->validate([
                'email' => 'nullable|email',
            ]);

            $participant = MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'public_id' => (string) Str::ulid(),
                'role' => 'participant',
                'status' => ($meeting->settings['lobby_enabled'] ?? true) ? 'waiting' : 'admitted',
                'metadata' => [
                    'guest_name' => $request->input('name', 'Guest'),
                    'guest_email' => $request->input('email'),
                ],
            ]);

            // Save participant ID to session so broadcast auth works for guests
            session(['meeting_participant_id' => $participant->public_id]);
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

        // Notify host when a participant enters the waiting room
        if ($participant->status === 'waiting') {
            broadcast(new \App\Events\Meetings\MeetingSignal(
                $meeting,
                $participant->public_id,
                'participant-waiting',
                [
                    'participant_id' => $participant->public_id,
                    'display_name' => $participant->metadata['guest_name'] ?? ($participant->user?->name ?? 'Someone'),
                ]
            ));
        }

        return response()->json([
            'data' => [
                'meeting' => new MeetingResource($meeting->load(['host', 'participants.user'])),
                'participant' => $participant,
            ]
        ]);
    }

    /**
     * Authenticate meeting channel subscriptions for guests and users.
     */
    public function broadcastingAuth(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        $request->validate([
            'socket_id' => 'required|string',
            'channel_name' => 'required|string',
        ]);

        $channelName = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        $isPresence = str_starts_with($channelName, 'presence-');
        $actualChannel = $isPresence ? substr($channelName, 9) : $channelName;

        if (!preg_match('/^meeting\.([a-zA-Z0-9_-]+)$/', $actualChannel, $matches)) {
            return response()->json(['message' => 'Invalid meeting channel'], 403);
        }

        $meetingId = $matches[1];
        $meeting = Meeting::where('public_id', $meetingId)->first();
        if (!$meeting) return response()->json(['message' => 'Meeting not found'], 404);

        $user = Auth::user();

        // 1. If user is the host
        if ($user && $meeting->user_id === $user->id) {
            $hostParticipant = MeetingParticipant::where('meeting_id', $meeting->id)
                ->where('user_id', $user->id)
                ->first();

            $participantId = $hostParticipant ? $hostParticipant->public_id : $user->public_id;
            $userData = [
                'public_id' => $participantId,
                'name' => $user->name,
                'avatar' => $user->avatar_url,
                'role' => 'host',
                'status' => 'admitted'
            ];

            return $this->generatePusherAuth($channelName, $socketId, $participantId, $userData, $isPresence);
        }

        // 2. Participant check (guests and registered users)
        $participantId = $request->header('X-Participant-ID') ?: (session('meeting_participant_id') ?: session('participant_id'));

        $participantQuery = MeetingParticipant::where('meeting_id', $meeting->id);

        if ($user) {
            $participantQuery->where(function($q) use ($user, $participantId) {
                $q->where('user_id', $user->id);
                if ($participantId) {
                    $q->orWhere('public_id', $participantId);
                }
            });
        } else {
            if (!$participantId) {
                return response()->json(['message' => 'Unauthorized. No participant ID.'], 403);
            }
            $participantQuery->where('public_id', $participantId);
        }

        $participant = $participantQuery->first();

        if (!$participant) {
            return response()->json(['message' => 'Unauthorized. Participant not found in this meeting.'], 403);
        }

        $pId = $participant->public_id;
        $userData = [
            'public_id' => $pId,
            'name' => $participant->metadata['guest_name'] ?? ($participant->user?->name ?? 'Guest'),
            'avatar' => $participant->user?->avatar_url ?? null,
            'role' => $participant->role,
            'status' => $participant->status,
        ];

        return $this->generatePusherAuth($channelName, $socketId, $pId, $userData, $isPresence);
    }

    private function generatePusherAuth($channelName, $socketId, $userId, $userData, $isPresence): \Illuminate\Http\Response
    {
        $connection = config('broadcasting.default');
        $config = config("broadcasting.connections.{$connection}");

        $pusher = new \Pusher\Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            $config['options'] ?? []
        );

        if ($isPresence) {
            $auth = $pusher->presence_auth($channelName, $socketId, $userId, $userData);
            return response($auth)->header('Content-Type', 'application/json');
        }

        $auth = $pusher->socket_auth($channelName, $socketId);
        return response($auth)->header('Content-Type', 'application/json');
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
        $isModerator = $meeting->user_id === Auth::id() || 
                       MeetingParticipant::where('meeting_id', $meeting->id)
                           ->where('user_id', Auth::id())
                           ->where('role', 'co-host')
                           ->exists();

        if (!$isModerator) {
            return response()->json(['message' => 'Only moderators can admit participants.'], 403);
        }

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }

        $participant->update(['status' => 'admitted']);

        // 1. Broadcast admission event (Echo channel event)
        broadcast(new MeetingParticipantAdmitted($meeting, $participant));

        // 2. ALSO send an explicit signal to the participant as a fallback
        // This ensures the guest's handleSignal logic also catches it.
        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            'system',
            'participant-admitted',
            ['admitted_participant_id' => $participant->public_id],
            $participant->public_id
        ));

        return response()->json(['message' => 'Participant admitted.', 'participant' => $participant]);
    }

    /**
     * Reject a participant from the waiting room.
     */
    public function reject(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $isModerator = $meeting->user_id === Auth::id() || 
                       MeetingParticipant::where('meeting_id', $meeting->id)
                           ->where('user_id', Auth::id())
                           ->where('role', 'co-host')
                           ->exists();

        if (!$isModerator) {
            return response()->json(['message' => 'Only moderators can reject participants.'], 403);
        }

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }

        $participant->update(['status' => 'rejected']);

        // Send signal to the participant
        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            'system',
            'participant-rejected',
            ['targetId' => $participant->public_id],
            $participant->public_id
        ));

        return response()->json(['message' => 'Participant rejected.']);
    }

    /**
     * Force mute a participant.
     */
    public function mute(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $isModerator = $meeting->user_id === Auth::id() || 
                       MeetingParticipant::where('meeting_id', $meeting->id)
                           ->where('user_id', Auth::id())
                           ->where('role', 'co-host')
                           ->exists();

        if (!$isModerator) {
            return response()->json(['message' => 'Only moderators can mute participants.'], 403);
        }
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }
        $participant->update(['is_muted_by_host' => true]);
        return response()->json(['message' => 'Participant muted.']);
    }

    /**
     * Allow unmute.
     */
    public function unmute(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can modify participants.'], 403);
        }
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }
        $participant->update(['is_muted_by_host' => false]);
        return response()->json(['message' => 'Participant can unmute.']);
    }

    /**
     * Force camera off.
     */
    public function cameraOff(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $isModerator = $meeting->user_id === Auth::id() || 
                       MeetingParticipant::where('meeting_id', $meeting->id)
                           ->where('user_id', Auth::id())
                           ->where('role', 'co-host')
                           ->exists();

        if (!$isModerator) {
            return response()->json(['message' => 'Only moderators can modify participants.'], 403);
        }
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }
        $participant->update(['is_camera_disabled_by_host' => true]);
        return response()->json(['message' => 'Participant camera disabled.']);
    }

    /**
     * Allow camera.
     */
    public function cameraAllow(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can modify participants.'], 403);
        }
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }
        $participant->update(['is_camera_disabled_by_host' => false]);
        return response()->json(['message' => 'Participant camera allowed.']);
    }

    /**
     * Kick a participant.
     */
    public function kick(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $isModerator = $meeting->user_id === Auth::id() || 
                       MeetingParticipant::where('meeting_id', $meeting->id)
                           ->where('user_id', Auth::id())
                           ->where('role', 'co-host')
                           ->exists();

        if (!$isModerator) {
            return response()->json(['message' => 'Only moderators can modify participants.'], 403);
        }
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }
        $participant->update(['status' => 'rejected']);
        return response()->json(['message' => 'Participant kicked.']);
    }

    /**
     * Promote a participant to co-host.
     */
    public function promote(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        // Only the true creator/host can promote co-hosts
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the meeting host can promote participants.'], 403);
        }

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }

        $participant->update(['role' => 'co-host']);

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'role-changed',
            [
                'targetId' => $participant->public_id,
                'role' => 'co-host'
            ]
        ));

        return response()->json(['message' => 'Participant promoted to co-host.', 'participant' => $participant]);
    }

    /**
     * Demote a co-host back to participant.
     */
    public function demote(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        // Only the true creator/host can demote
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the meeting host can demote participants.'], 403);
        }

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }

        $participant->update(['role' => 'participant']);

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'role-changed',
            [
                'targetId' => $participant->public_id,
                'role' => 'participant'
            ]
        ));

        return response()->json(['message' => 'Participant demoted.', 'participant' => $participant]);
    }

    /**
     * Get meeting chat messages.
     */
    public function getMessages(Request $request, Meeting $meeting): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $messages = \App\Models\MeetingMessage::where('meeting_id', $meeting->id)
            ->orderBy('created_at', 'asc')
            ->get();
            
        return \App\Http\Resources\MeetingMessageResource::collection($messages);
    }

    /**
     * Send a meeting chat message.
     */
    public function sendMessage(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate([
            'participant_public_id' => 'required|string',
            'body' => 'required|string|max:2000',
        ]);

        $key = "meeting_chat_rate_limit:{$request->participant_public_id}";
        $count = (int) \Illuminate\Support\Facades\Cache::get($key, 0);

        if ($count >= 20) {
            return response()->json(['message' => 'Too many messages. Please wait before sending more.'], 429);
        }

        \Illuminate\Support\Facades\Cache::put($key, $count + 1, 60);

        $body = strip_tags($request->body);

        $message = \App\Models\MeetingMessage::create([
            'meeting_id' => $meeting->id,
            'participant_public_id' => $request->participant_public_id,
            'body' => $body,
        ]);

        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            $message->participant_public_id, // sender
            'chat-message',                  // signalType
            [
                'id' => $message->id,
                'participant_public_id' => $message->participant_public_id,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ]
        ));

        return response()->json(new \App\Http\Resources\MeetingMessageResource($message), 201);
    }

    /**
     * Lock the meeting.
     */
    public function lock(Request $request, Meeting $meeting): JsonResponse
    {
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can lock the meeting.'], 403);
        }

        $meeting->update(['is_locked' => true]);

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-locked',
            ['is_locked' => true]
        ));

        return response()->json(['message' => 'Meeting locked.']);
    }

    /**
     * Unlock the meeting.
     */
    public function unlock(Request $request, Meeting $meeting): JsonResponse
    {
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can unlock the meeting.'], 403);
        }

        $meeting->update(['is_locked' => false]);

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-locked',
            ['is_locked' => false]
        ));

        return response()->json(['message' => 'Meeting unlocked.']);
    }

    /**
     * End the meeting for all participants (host only).
     */
    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        if ($meeting->user_id !== Auth::id()) {
            return response()->json(['message' => 'Only the host can end the meeting.'], 403);
        }

        $meeting->update(['status' => 'ended']);

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new \App\Events\Meetings\MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-ended',
            ['ended_by' => $hostParticipant?->public_id ?? 'system']
        ));

        return response()->json(['message' => 'Meeting ended.']);
    }
}
