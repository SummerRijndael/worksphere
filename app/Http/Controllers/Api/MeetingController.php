<?php

namespace App\Http\Controllers\Api;

use App\Contracts\MeetingServiceContract;
use App\Events\Meetings\MeetingSignal;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingMessageResource;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Services\Chat\ChatPipeline;
use App\Services\MeetingChatMediaService;
use App\Support\MeetingParticipantSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Cookie;

class MeetingController extends Controller
{
    private const ALLOWED_SIGNAL_TYPES = [
        'allow-camera',
        'allow-unmute',
        'annotation-update',
        'camera-toggle',
        'force-camera-off',
        'force-mute',
        'force-stop-screen-share',
        'hand-toggle',
        'laser-mode-changed',
        'participant-joined',
        'participant-kicked',
        'reaction',
        'request-media-info',
        'role-changed',
        'screen-share-toggle',
        'signal',
    ];

    private const MEDIA_TOGGLE_SIGNAL_TYPES = [
        'camera-toggle',
        'screen-share-toggle',
    ];

    public function __construct(
        protected MeetingServiceContract $meetingService,
        protected ChatPipeline $chatPipeline
    ) {}

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

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Meeting::class);

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'settings' => 'nullable|array',
            'settings.guest_access' => 'sometimes|boolean',
            'settings.lobby_enabled' => 'sometimes|boolean',
            'settings.require_host_or_cohost_present' => 'sometimes|boolean',
            'settings.screen_share_host_cohost_only' => 'sometimes|boolean',
            'password' => [
                'nullable',
                'string',
                'max:100',
                Password::min(8)->mixedCase()->numbers(),
                function ($attribute, $value, $fail) use ($request) {
                    if (
                        ($request->input('settings.guest_access') ?? false) &&
                        ! $request->boolean('auto_generate_password') &&
                        empty($value)
                    ) {
                        $fail('A password is required when guest access is enabled.');
                    }
                },
            ],
            'auto_generate_password' => 'nullable|boolean',
            'save_to_calendar' => 'nullable|boolean',
            'reminder_minutes_before' => 'nullable|integer|min:0',
            'send_invite' => 'nullable|boolean',
            'participants' => 'nullable|array',
            'participants.*.type' => 'required_with:participants|in:user,email',
            'participants.*.id' => 'nullable|string',
            'participants.*.email' => 'nullable|email',
            'participants.*.name' => 'nullable|string',
        ]);

        $existing = Meeting::where('user_id', Auth::id())
            ->where('title', $request->title)
            ->where('start_time', $request->start_time)
            ->where('created_at', '>', now()->subMinute())
            ->first();

        if ($existing) {
            return $this->meetingResponse($existing);
        }

        $password = $request->password;
        if ($request->auto_generate_password) {
            // Generate a strong password and return it once to the host.
            $password = Str::password(12, true, true, false, false);
        }

        $data = $request->only(['title', 'description', 'start_time', 'end_time', 'settings']);
        $data['password'] = $password;
        $data['save_to_calendar'] = $request->boolean('save_to_calendar');
        $data['reminder_minutes_before'] = $request->input('reminder_minutes_before');
        $data['send_invite'] = $request->boolean('send_invite');
        $data['participants'] = $request->input('participants', []);

        try {
            $meeting = $this->meetingService->createMeeting($request->user(), $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = $e->errors();
            $firstError = collect($errors)->flatten()->first();

            return response()->json([
                'message' => $firstError ?: $e->getMessage(),
                'errors' => $errors,
            ], 422);
        }

        return $this->meetingResponse($meeting, $password);
    }

    public function show(Meeting $meeting): MeetingResource
    {
        $this->authorize('view', $meeting);

        return new MeetingResource($meeting->load(['host', 'participants.user']));
    }

    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate([
            'email' => 'nullable|email',
            'is_companion' => 'nullable|boolean',
        ]);

        $participantSessionId = null;
        if (! $request->user()) {
            $participantSessionId = MeetingParticipantSession::resolveGuestParticipantId($request, $meeting);
        }

        try {
            $result = $this->meetingService->joinMeeting(
                $meeting,
                $request->user(),
                $request->input('name', 'Guest'),
                $request->input('email'),
                $request->input('password'),
                $participantSessionId,
                $request->boolean('is_companion')
            );

            $participantPublicId = (string) ($result['participant']->public_id ?? '');
            if ($participantPublicId !== '') {
                if (! $request->user()) {
                    session(['meeting_participant_id' => $participantPublicId]);
                }
            }

            $response = response()->json([
                'data' => [
                    'meeting' => new MeetingResource($result['meeting']),
                    'participant' => $result['participant'],
                ],
            ]);

            if ($participantPublicId !== '') {
                $response->headers->setCookie($this->meetingParticipantCookie($request, $meeting, $participantPublicId));
            }

            return $response;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'REQUIRES_PASSWORD')) {
                return response()->json([
                    'message' => 'Invalid meeting password.',
                    'requires_password' => true,
                ], 403);
            }

            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function broadcastingAuth(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        $request->validate([
            'socket_id' => 'required|string',
            'channel_name' => 'required|string',
        ]);

        $channelName = $request->input('channel_name');
        $actualChannel = str_starts_with($channelName, 'presence-') ? substr($channelName, 9) : $channelName;

        if (! preg_match('/^meeting\.([a-zA-Z0-9_-]+)$/', $actualChannel, $matches)) {
            return response()->json(['message' => 'Invalid meeting channel'], 403);
        }

        $meeting = Meeting::where('public_id', $matches[1])->first();
        if (! $meeting) {
            return response()->json(['message' => 'Meeting not found'], 404);
        }

        $participantSessionId = null;
        if (! $request->user()) {
            $participantSessionId = MeetingParticipantSession::resolveGuestParticipantId($request, $meeting);
        }

        try {
            return $this->meetingService->authenticateBroadcasting(
                $meeting,
                $request->user(),
                $channelName,
                $request->input('socket_id'),
                $participantSessionId
            );
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function signal(Request $request, Meeting $meeting): JsonResponse
    {
        $signalDataInput = $request->input('signal_data');
        $signalDiag = is_array($signalDataInput) ? ($signalDataInput['_diag'] ?? null) : null;

        $validator = Validator::make($request->all(), [
            'signal_type' => 'required|string|in:'.implode(',', self::ALLOWED_SIGNAL_TYPES),
            'signal_data' => 'present|array',
            'target_participant_public_id' => 'nullable|string',
            'sender_participant_public_id' => 'required|string',
        ]);
        if ($validator->fails()) {
            Log::channel('videocall')->warning('[SIGNAL] Validation failed', [
                'meeting' => $meeting->public_id,
                'user_id' => Auth::id(),
                'sender' => $request->input('sender_participant_public_id'),
                'target' => $request->input('target_participant_public_id'),
                'signal_type' => $request->input('signal_type'),
                'signal_data_type' => gettype($request->input('signal_data')),
                'errors' => $validator->errors()->toArray(),
                'diag' => $signalDiag,
                'ua' => (string) $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'Invalid signal payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $participantSessionId = null;
        if (! $user) {
            $participantSessionId = MeetingParticipantSession::resolveGuestParticipantId($request, $meeting);
            if (! $participantSessionId) {
                Log::channel('videocall')->warning('[SIGNAL] Missing or mismatched guest participant session', [
                    'meeting' => $meeting->public_id,
                    'sender' => strtolower((string) $request->sender_participant_public_id),
                    'signal_type' => $request->input('signal_type'),
                    'diag' => $signalDiag,
                    'ua' => (string) $request->userAgent(),
                ]);

                return response()->json(['message' => 'Mismatched signal session'], 403);
            }
        }
        $senderPublicId = strtolower($request->sender_participant_public_id);

        $senderQuery = MeetingParticipant::where('meeting_id', $meeting->id)
            ->whereRaw('LOWER(public_id) = ?', [$senderPublicId])
            ->where('status', 'admitted');

        // Security: Ensure requester owns this participant ID
        if ($user) {
            $senderQuery->where('user_id', $user->id);
        } else {
            if (strtolower($participantSessionId) !== $senderPublicId) {
                Log::channel('videocall')->warning('[SIGNAL] Session mismatch', [
                    'meeting' => $meeting->public_id,
                    'sender' => $senderPublicId,
                    'participant_session_id' => $participantSessionId,
                    'signal_type' => $request->input('signal_type'),
                    'diag' => $signalDiag,
                    'ua' => (string) $request->userAgent(),
                ]);
                return response()->json(['message' => 'Mismatched signal session'], 403);
            }
        }

        $sender = $senderQuery->first();

        if (! $sender) {
            Log::channel('videocall')->warning('[SIGNAL] Unauthorized sender', [
                'meeting' => $meeting->public_id,
                'sender' => $senderPublicId,
                'signal_type' => $request->input('signal_type'),
                'user_id' => Auth::id(),
                'diag' => $signalDiag,
                'ua' => (string) $request->userAgent(),
            ]);
            return response()->json(['message' => 'Unauthorized or invalid sender session'], 403);
        }

        $signalType = (string) $request->signal_type;
        $signalData = $request->signal_data ?? [];
        $signalDiag = is_array($signalData) && is_array($signalData['_diag'] ?? null)
            ? $signalData['_diag']
            : null;

        if (in_array($signalType, self::MEDIA_TOGGLE_SIGNAL_TYPES, true)) {
            $rateKey = sprintf(
                'meeting_media_toggle:%s:%s:%s',
                $meeting->id,
                strtolower((string) $sender->public_id),
                $signalType
            );

            $maxAttempts = 60;
            $decaySeconds = 10;
            if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
                Log::channel('videocall')->warning('[SIGNAL] Media toggle rate limited', [
                    'meeting' => $meeting->public_id,
                    'sender' => $sender->public_id,
                    'signal_type' => $signalType,
                    'retry_after_seconds' => RateLimiter::availableIn($rateKey),
                    'diag' => $signalDiag,
                ]);
                return response()->json([
                    'message' => 'Too many media updates sent too quickly. Please slow down.',
                    'retry_after_seconds' => RateLimiter::availableIn($rateKey),
                ], 429);
            }
            RateLimiter::hit($rateKey, $decaySeconds);
        }

        if ($signalType === 'screen-share-toggle') {
            $isSharing = (bool) ($signalData['sharing'] ?? false);
            if ($isSharing) {
                $restrictToModerators = (bool) ($meeting->settings['screen_share_host_cohost_only'] ?? false);
                $isModerator = in_array($sender->role, ['host', 'co-host'], true);

                if ($restrictToModerators && ! $isModerator) {
                    Log::channel('videocall')->warning('[SIGNAL] Screen share denied by role policy', [
                        'meeting' => $meeting->public_id,
                        'sender' => $sender->public_id,
                        'sender_role' => $sender->role,
                        'signal_type' => $signalType,
                        'diag' => $signalDiag,
                    ]);
                    return response()->json(['message' => 'Only host or co-host can share screen in this meeting.'], 403);
                }
            }
        }

        if ($signalType === 'force-stop-screen-share') {
            $isModerator = in_array($sender->role, ['host', 'co-host'], true);
            if (! $isModerator) {
                Log::channel('videocall')->warning('[SIGNAL] Force stop denied by role policy', [
                    'meeting' => $meeting->public_id,
                    'sender' => $sender->public_id,
                    'sender_role' => $sender->role,
                    'signal_type' => $signalType,
                    'diag' => $signalDiag,
                ]);
                return response()->json(['message' => 'Only host or co-host can control screen sharing.'], 403);
            }

            $targetId = strtolower((string) ($signalData['targetId'] ?? ''));
            if ($targetId === '') {
                Log::channel('videocall')->warning('[SIGNAL] Force stop missing target', [
                    'meeting' => $meeting->public_id,
                    'sender' => $sender->public_id,
                    'signal_type' => $signalType,
                    'signal_data' => $signalData,
                    'diag' => $signalDiag,
                ]);
                return response()->json(['message' => 'Missing target participant.'], 422);
            }

            $targetExists = MeetingParticipant::where('meeting_id', $meeting->id)
                ->whereRaw('LOWER(public_id) = ?', [$targetId])
                ->where('status', 'admitted')
                ->exists();

            if (! $targetExists) {
                Log::channel('videocall')->warning('[SIGNAL] Force stop target missing', [
                    'meeting' => $meeting->public_id,
                    'sender' => $sender->public_id,
                    'target' => $targetId,
                    'signal_type' => $signalType,
                    'diag' => $signalDiag,
                ]);
                return response()->json(['message' => 'Target participant not found in meeting.'], 404);
            }
        }

        if ($signalType === 'signal' && (($signalData['type'] ?? null) === 'sfu-media-ready')) {
            Log::channel('videocall')->debug('[SIGNAL][SFU] media-ready', [
                'meeting' => $meeting->public_id,
                'sender' => $sender->public_id,
                'target' => $request->target_participant_public_id,
                'session_id' => $signalData['sessionId'] ?? null,
                'audio_mid' => $signalData['audioMid'] ?? null,
                'video_mid' => $signalData['videoMid'] ?? null,
                'screen_mid' => $signalData['screenMid'] ?? null,
                'room_id' => $signalData['current_room_id'] ?? null,
                'diag' => $signalDiag,
            ]);
        }

        if ($signalType === 'request-media-info') {
            Log::channel('videocall')->debug('[SIGNAL][SFU] request-media-info', [
                'meeting' => $meeting->public_id,
                'sender' => $sender->public_id,
                'target' => $request->target_participant_public_id,
                'diag' => $signalDiag,
            ]);
        }

        Log::channel('videocall')->debug('[SIGNAL] Broadcasting signal', [
            'meeting' => $meeting->public_id,
            'type' => $request->signal_type,
            'sender' => $sender->public_id,
            'target' => $request->target_participant_public_id,
            'data_type' => $request->signal_data['type'] ?? 'none',
            'diag' => $signalDiag,
            'ua' => (string) $request->userAgent(),
        ]);

        broadcast(new MeetingSignal(
            $meeting,
            $sender->public_id,
            $request->signal_type,
            $request->signal_data,
            $request->target_participant_public_id
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    public function update(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'settings' => 'nullable|array',
            'settings.guest_access' => 'sometimes|boolean',
            'settings.lobby_enabled' => 'sometimes|boolean',
            'settings.require_host_or_cohost_present' => 'sometimes|boolean',
            'settings.screen_share_host_cohost_only' => 'sometimes|boolean',
            'password' => [
                'nullable',
                'string',
                'max:100',
                Password::min(8)->mixedCase()->numbers(),
            ],
            'auto_generate_password' => 'nullable|boolean',
        ]);

        $data = $request->only(['title', 'description', 'start_time', 'end_time', 'status', 'settings']);
        $plainPassword = null;

        if ($request->has('auto_generate_password') && $request->auto_generate_password) {
            $plainPassword = Str::password(12, true, true, false, false);
            $data['password'] = $plainPassword;
        } elseif ($request->has('password')) {
            $data['password'] = $request->password;
            $plainPassword = $request->password;
        }

        $meeting = $this->meetingService->updateMeeting($meeting, $data);

        return $this->meetingResponse($meeting, $plainPassword);
    }

    public function updateSettings(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $current = $meeting->settings ?? [];
        $meeting->update(['settings' => array_merge($current, $validated['settings'])]);

        return response()->json(['message' => 'Settings updated.']);
    }

    public function destroy(Meeting $meeting): JsonResponse
    {
        $this->authorize('delete', $meeting);
        $this->meetingService->deleteMeeting($meeting);

        return response()->json(['message' => 'Meeting cancelled successfully']);
    }

    public function turnCredentials(Meeting $meeting): JsonResponse
    {
        return response()->json($this->meetingService->generateTurnCredentials());
    }

    public function sfuSessionNew(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized or participant not admitted.'], 403);
        }

        $request->validate([
            'sessionDescription' => 'required|array',
            'sessionDescription.sdp' => 'required|string',
        ]);

        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        if (! $appId || ! $secret) {
            return response()->json(['error' => 'SFU not configured'], 503);
        }

        $cfPayload = $request->only(['sessionDescription', 'tracks']);

        try {
            Log::channel('videocall')->info('[SFU] Creating new session', [
                'meeting' => $meeting->public_id,
                'tracks_count' => count($request->input('tracks', [])),
            ]);

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
            return response()->json(['error' => 'SFU Session Creation Timeout/Error', 'details' => $e->getMessage()], 500);
        }
    }

    public function sfuSessionTracks(Request $request, Meeting $meeting, string $sessionId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized or participant not admitted.'], 403);
        }

        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        Log::channel('videocall')->info('[SFU] Pulling tracks', [
            'meeting' => $meeting->public_id,
            'sessionId' => $sessionId,
            'tracks' => $request->input('tracks'),
            'has_session_description' => $request->filled('sessionDescription.sdp'),
        ]);

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/tracks/new", $request->only(['sessionDescription', 'tracks']));

            $responseData = $response->json();
            $trackSummary = collect($responseData['tracks'] ?? [])->map(function ($track) {
                return [
                    'trackName' => $track['trackName'] ?? null,
                    'mid' => $track['mid'] ?? null,
                    'errorCode' => $track['errorCode'] ?? null,
                    'requiresImmediateRenegotiation' => $track['requiresImmediateRenegotiation'] ?? null,
                ];
            })->values()->all();

            Log::channel('videocall')->debug('[SFU] tracks/new response', [
                'meeting' => $meeting->public_id,
                'sessionId' => $sessionId,
                'status' => $response->status(),
                'track_summary' => $trackSummary,
                'has_session_description' => is_array($responseData) && ! empty($responseData['sessionDescription']),
            ]);

            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Meeting Cloudflare tracks/new error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'sessionId' => $sessionId,
                ]);
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'SFU Track Pull Error', 'details' => $e->getMessage()], 500);
        }
    }

    public function sfuSessionRenegotiate(Request $request, Meeting $meeting, string $sessionId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized or participant not admitted.'], 403);
        }

        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        $method = strtolower($request->method());
        $data = $request->all();

        if (isset($data['sessionDescription']['type']) &&
            ($data['sessionDescription']['type'] === 'rollback' || $data['sessionDescription']['type'] === 'offer') &&
            array_key_exists('sdp', $data['sessionDescription']) &&
            $data['sessionDescription']['sdp'] === null) {
            $data['sessionDescription']['sdp'] = '';
        }

        Log::channel('videocall')->info('[SFU] Renegotiating session', [
            'meeting' => $meeting->public_id,
            'sessionId' => $sessionId,
            'method' => $method,
        ]);

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->send($method, "https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/renegotiate", [
                    'json' => ! empty($data) ? $data : null,
                ]);

            $responseData = $response->json();
            if (! $response->successful()) {
                return response()->json(['error' => 'Renegotiation Error'], $response->status());
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'SFU Renegotiation Exception', 'details' => $e->getMessage()], 500);
        }
    }

    public function sfuTracksUpdate(Request $request, Meeting $meeting, string $sessionId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized or participant not admitted.'], 403);
        }

        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        Log::channel('videocall')->info('[SFU] Updating tracks (simulcast layer switch)', [
            'meeting' => $meeting->public_id,
            'sessionId' => $sessionId,
            'tracks' => $request->input('tracks'),
        ]);

        try {
            $response = Http::withToken($secret)
                ->timeout(30)
                ->put("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/tracks/update", $request->only(['tracks']));

            $responseData = $response->json();
            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Cloudflare tracks/update error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'sessionId' => $sessionId,
                ]);
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'SFU Track Update Error', 'details' => $e->getMessage()], 500);
        }
    }

    public function sfuTracksClose(Request $request, Meeting $meeting, string $sessionId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized or participant not admitted.'], 403);
        }

        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        Log::channel('videocall')->info('[SFU] Closing tracks', [
            'meeting' => $meeting->public_id,
            'sessionId' => $sessionId,
            'tracks' => $request->input('tracks'),
        ]);

        try {
            $payload = $request->only(['tracks', 'force', 'sessionDescription']);
            $response = Http::withToken($secret)
                ->timeout(30)
                ->put("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/tracks/close", $payload);

            $responseData = $response->json();
            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Cloudflare tracks/close error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'sessionId' => $sessionId,
                ]);
            }

            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'SFU Track Close Error', 'details' => $e->getMessage()], 500);
        }
    }

    public function admit(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Participant does not belong to this meeting.'], 404);
        }

        $participant = $this->meetingService->admitParticipant($meeting, $participant);

        return response()->json(['message' => 'Participant admitted.', 'participant' => $participant]);
    }

    public function reject(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);

        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->meetingService->rejectParticipant($meeting, $participant);

        return response()->json(['message' => 'Participant rejected.']);
    }

    public function mute(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $participant->update(['is_muted_by_host' => true]);

        return response()->json(['message' => 'Participant muted.']);
    }

    public function unmute(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $participant->update(['is_muted_by_host' => false]);

        return response()->json(['message' => 'Participant can unmute.']);
    }

    public function cameraOff(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $participant->update(['is_camera_disabled_by_host' => true]);

        return response()->json(['message' => 'Participant camera disabled.']);
    }

    public function cameraAllow(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $participant->update(['is_camera_disabled_by_host' => false]);

        return response()->json(['message' => 'Participant camera allowed.']);
    }

    public function kick(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $participant->update(['status' => 'rejected']);

        return response()->json(['message' => 'Participant kicked.']);
    }

    public function promote(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $participant = $this->meetingService->promoteParticipant($meeting, $participant);

        return response()->json(['message' => 'Participant promoted to co-host.', 'participant' => $participant]);
    }

    public function demote(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host
        if ($participant->meeting_id !== $meeting->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $participant = $this->meetingService->demoteParticipant($meeting, $participant);

        return response()->json(['message' => 'Participant demoted.', 'participant' => $participant]);
    }

    public function getMessages(Request $request, Meeting $meeting): AnonymousResourceCollection|JsonResponse
    {
        $isHost = $request->user() && $meeting->user_id === $request->user()->id;
        $participant = $this->resolveParticipant($request, $meeting);

        if (! $isHost && (! $participant || ! in_array($participant->status, ['admitted', 'waiting'], true))) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! config('chat_pipeline.meeting_chat_adapter_enabled', true)) {
            return $this->legacyGetMessages($meeting, $request);
        }

        try {
            $messages = $this->chatPipeline->fetchMessages(
                (string) config('chat_pipeline.meeting_chat_adapter', 'meeting'),
                [
                    'meeting' => $meeting,
                    'participant' => $participant,
                    'thread_root_id' => $request->integer('thread_root_id') ?: null,
                ],
                max(1, min(500, $request->integer('limit', 200))),
                $request->filled('before') ? (string) $request->input('before') : null
            );

            return response()->json(['data' => $messages]);
        } catch (\Throwable $e) {
            Log::channel('videocall')->error('[MEETING-CHAT] Adapter getMessages failed, falling back to legacy', [
                'meeting' => $meeting->public_id,
                'error' => $e->getMessage(),
            ]);

            return $this->legacyGetMessages($meeting, $request);
        }
    }

    public function sendMessage(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'body' => 'nullable|string|max:2000',
            'temp_id' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'reply_to' => 'nullable',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:5120',
        ]);

        /** @var array<UploadedFile> $files */
        $files = $request->file('files', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        $validated['body'] = trim((string) ($validated['body'] ?? ''));

        if ($validated['body'] === '' && empty($files)) {
            return response()->json(['message' => 'Message cannot be empty.'], 422);
        }

        if (! empty($files)) {
            try {
                app(MeetingChatMediaService::class)->validateFiles($files);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $validated['files'] = $files;
        }

        $key = sprintf('meeting_chat_%s_%s', $meeting->id, strtolower($participant->public_id));
        $count = (int) Cache::get($key, 0);

        if ($count >= 20) {
            return response()->json(['message' => 'Too many messages.'], 429);
        }
        Cache::put($key, $count + 1, 60);

        $burstKey = sprintf('meeting_chat_burst_%s_%s', $meeting->id, strtolower($participant->public_id));
        if (RateLimiter::tooManyAttempts($burstKey, 8)) {
            return response()->json(['message' => 'Sending too fast. Please slow down.'], 429);
        }
        RateLimiter::hit($burstKey, 2);

        if ($validated['body'] !== '') {
            $normalizedBody = trim((string) preg_replace('/\s+/u', ' ', Str::lower((string) $validated['body'])));
            if ($normalizedBody !== '') {
                $duplicateKey = sprintf(
                    'meeting_chat_duplicate_%s_%s_%s',
                    $meeting->id,
                    strtolower($participant->public_id),
                    sha1($normalizedBody)
                );
                if (Cache::has($duplicateKey)) {
                    return response()->json(['message' => 'Duplicate message detected. Please wait a moment.'], 429);
                }
                Cache::put($duplicateKey, true, 4);
            }
        }

        if (! config('chat_pipeline.meeting_chat_adapter_enabled', true)) {
            return $this->legacySendMessage($meeting, $participant, $validated);
        }

        try {
            $message = $this->chatPipeline->sendMessage(
                (string) config('chat_pipeline.meeting_chat_adapter', 'meeting'),
                [
                    'meeting' => $meeting,
                    'participant' => $participant,
                ],
                $validated
            );

            return response()->json(['data' => $message], 201);
        } catch (\Throwable $e) {
            Log::channel('videocall')->error('[MEETING-CHAT] Adapter sendMessage failed, falling back to legacy', [
                'meeting' => $meeting->public_id,
                'participant' => $participant->public_id,
                'error' => $e->getMessage(),
            ]);

            return $this->legacySendMessage($meeting, $participant, $validated);
        }
    }

    public function pinMessage(Request $request, Meeting $meeting, string $messageId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $this->isChatModerator($participant)) {
            return response()->json(['message' => 'Only host or co-host can pin messages.'], 403);
        }

        if (! config('chat_pipeline.meeting_chat_adapter_enabled', true)) {
            return $this->legacyPinMessage($meeting, $participant, $messageId);
        }

        try {
            $message = $this->chatPipeline->pinMessage(
                (string) config('chat_pipeline.meeting_chat_adapter', 'meeting'),
                [
                    'meeting' => $meeting,
                    'participant' => $participant,
                ],
                $messageId
            );

            broadcast(new MeetingSignal(
                $meeting,
                $participant->public_id,
                'chat-message-pinned',
                ['message' => $message]
            ));

            return response()->json(['data' => $message]);
        } catch (\Throwable $e) {
            Log::channel('videocall')->error('[MEETING-CHAT] Adapter pinMessage failed, falling back to legacy', [
                'meeting' => $meeting->public_id,
                'participant' => $participant->public_id,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return $this->legacyPinMessage($meeting, $participant, $messageId);
        }
    }

    public function updateMessage(Request $request, Meeting $meeting, string $messageId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = $this->resolveMeetingMessage($meeting, $messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        if (! $this->canEditChatMessage($participant, $message)) {
            return response()->json(['message' => 'Only the original sender can edit this message.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);
        $validated['body'] = trim((string) $validated['body']);
        if ($validated['body'] === '') {
            return response()->json(['message' => 'Message body cannot be empty.'], 422);
        }

        if (! config('chat_pipeline.meeting_chat_adapter_enabled', true)) {
            return $this->legacyUpdateMessage($meeting, $participant, $messageId, $validated);
        }

        try {
            $updated = $this->chatPipeline->editMessage(
                (string) config('chat_pipeline.meeting_chat_adapter', 'meeting'),
                [
                    'meeting' => $meeting,
                    'participant' => $participant,
                ],
                $messageId,
                $validated
            );

            broadcast(new MeetingSignal(
                $meeting,
                $participant->public_id,
                'chat-message-edited',
                ['message' => $updated]
            ));

            return response()->json(['data' => $updated]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::channel('videocall')->error('[MEETING-CHAT] Adapter updateMessage failed, falling back to legacy', [
                'meeting' => $meeting->public_id,
                'participant' => $participant->public_id,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return $this->legacyUpdateMessage($meeting, $participant, $messageId, $validated);
        }
    }

    public function deleteMessage(Request $request, Meeting $meeting, string $messageId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = $this->resolveMeetingMessage($meeting, $messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        if (! $this->canDeleteChatMessage($participant, $message)) {
            return response()->json(['message' => 'Only the sender, host, or co-host can delete this message.'], 403);
        }

        if (! config('chat_pipeline.meeting_chat_adapter_enabled', true)) {
            return $this->legacyDeleteMessage($meeting, $participant, $messageId);
        }

        try {
            $deleted = $this->chatPipeline->deleteMessage(
                (string) config('chat_pipeline.meeting_chat_adapter', 'meeting'),
                [
                    'meeting' => $meeting,
                    'participant' => $participant,
                ],
                $messageId
            );

            broadcast(new MeetingSignal(
                $meeting,
                $participant->public_id,
                'chat-message-deleted',
                ['message' => $deleted]
            ));

            return response()->json(['data' => $deleted]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::channel('videocall')->error('[MEETING-CHAT] Adapter deleteMessage failed, falling back to legacy', [
                'meeting' => $meeting->public_id,
                'participant' => $participant->public_id,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return $this->legacyDeleteMessage($meeting, $participant, $messageId);
        }
    }

    public function toggleMessageReaction(Request $request, Meeting $meeting, string $messageId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = $this->resolveMeetingMessage($meeting, $messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $validated = $request->validate([
            'reaction' => 'required|string|in:like,laugh,100,hundred,sad,love,angry,scared,care',
        ]);

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            return response()->json(['message' => 'Cannot react to deleted messages.'], 422);
        }

        $reaction = (string) $validated['reaction'];
        if ($reaction === '100') {
            $reaction = 'hundred';
        }
        $reactions = is_array($metadata['reactions'] ?? null) ? $metadata['reactions'] : [];
        $actorId = strtolower((string) $participant->public_id);
        $currentBuckets = is_array($reactions[$reaction] ?? null) ? $reactions[$reaction] : [];
        if ($reaction === 'hundred' && is_array($reactions['100'] ?? null)) {
            $currentBuckets = array_merge($currentBuckets, $reactions['100']);
        }
        $currentForRequested = array_values(array_filter(
            array_map(static fn ($id) => strtolower((string) $id), $currentBuckets)
        ));
        $alreadyReacted = in_array($actorId, $currentForRequested, true);

        // One reaction per participant per message:
        // remove actor from every reaction bucket first, then add only requested key (unless toggling off same key).
        foreach ($reactions as $key => $ids) {
            $normalized = array_values(array_filter(
                array_map(static fn ($id) => strtolower((string) $id), is_array($ids) ? $ids : []),
                static fn (string $id) => $id !== ''
            ));
            $normalized = array_values(array_filter(
                $normalized,
                static fn (string $id) => $id !== $actorId
            ));

            if (empty($normalized)) {
                unset($reactions[$key]);
                continue;
            }
            $reactions[$key] = array_values(array_unique($normalized));
        }

        if (! $alreadyReacted) {
            $next = is_array($reactions[$reaction] ?? null) ? $reactions[$reaction] : [];
            $next[] = $actorId;
            $reactions[$reaction] = array_values(array_unique(array_filter(
                array_map(static fn ($id) => strtolower((string) $id), $next),
                static fn (string $id) => $id !== ''
            )));
        }

        if (empty($reactions)) {
            unset($metadata['reactions']);
        } else {
            $metadata['reactions'] = $reactions;
        }

        $message->forceFill([
            'metadata' => $metadata,
        ])->save();

        $payload = $this->serializeMeetingMessage($message);

        broadcast(new MeetingSignal(
            $meeting,
            $participant->public_id,
            'chat-message-reaction',
            [
                'message' => $payload,
                'reaction' => $reaction,
                'active' => ! $alreadyReacted,
            ]
        ));

        return response()->json(['data' => $payload]);
    }

    public function unpinMessage(Request $request, Meeting $meeting, string $messageId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $this->isChatModerator($participant)) {
            return response()->json(['message' => 'Only host or co-host can unpin messages.'], 403);
        }

        if (! config('chat_pipeline.meeting_chat_adapter_enabled', true)) {
            return $this->legacyUnpinMessage($meeting, $participant, $messageId);
        }

        try {
            $message = $this->chatPipeline->unpinMessage(
                (string) config('chat_pipeline.meeting_chat_adapter', 'meeting'),
                [
                    'meeting' => $meeting,
                    'participant' => $participant,
                ],
                $messageId
            );

            broadcast(new MeetingSignal(
                $meeting,
                $participant->public_id,
                'chat-message-unpinned',
                ['message' => $message]
            ));

            return response()->json(['data' => $message]);
        } catch (\Throwable $e) {
            Log::channel('videocall')->error('[MEETING-CHAT] Adapter unpinMessage failed, falling back to legacy', [
                'meeting' => $meeting->public_id,
                'participant' => $participant->public_id,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return $this->legacyUnpinMessage($meeting, $participant, $messageId);
        }
    }

    public function clearPinnedMessages(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $this->isChatModerator($participant)) {
            return response()->json(['message' => 'Only host or co-host can clear pinned messages.'], 403);
        }

        $pinnedMessages = MeetingMessage::where('meeting_id', $meeting->id)
            ->where('is_pinned', true)
            ->get();

        if ($pinnedMessages->isEmpty()) {
            return response()->json(['data' => []]);
        }

        DB::transaction(function () use ($pinnedMessages) {
            foreach ($pinnedMessages as $message) {
                $message->forceFill([
                    'is_pinned' => false,
                    'pinned_at' => null,
                    'pinned_by_participant_public_id' => null,
                ])->save();
            }
        });

        $payloads = [];
        foreach ($pinnedMessages as $message) {
            $payload = $this->serializeMeetingMessage($message->refresh());
            $payloads[] = $payload;

            broadcast(new MeetingSignal(
                $meeting,
                $participant->public_id,
                'chat-message-unpinned',
                ['message' => $payload]
            ));
        }

        return response()->json(['data' => $payloads]);
    }

    protected function legacyGetMessages(Meeting $meeting, ?Request $request = null): AnonymousResourceCollection
    {
        $query = MeetingMessage::where('meeting_id', $meeting->id)
            ->with([
                'participant:id,user_id,public_id,metadata',
                'participant.user:id,name',
                'media',
                'replyTo:id,participant_public_id,body,created_at',
                'threadRoot:id,participant_public_id,body,created_at',
                'pinnedByParticipant:id,user_id,public_id,metadata',
                'pinnedByParticipant.user:id,name',
            ])
            ->withCount('replies');

        $limit = max(1, min(500, (int) ($request?->integer('limit', 200) ?? 200)));
        $before = $request?->input('before');
        $threadRootId = $request?->integer('thread_root_id') ?: null;

        if ($threadRootId) {
            $query->where(function ($q) use ($threadRootId) {
                $q->where('id', $threadRootId)
                    ->orWhere('thread_root_message_id', $threadRootId);
            });
        }

        if (is_string($before) && ctype_digit($before)) {
            $query->where('id', '<', (int) $before);
        }

        if (! $before && ! $threadRootId) {
            $messages = $query
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->sortBy(fn (MeetingMessage $message) => [
                    $message->created_at?->getTimestamp() ?? 0,
                    (int) $message->id,
                ])
                ->values();
        } else {
            $messages = $query
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->get();
        }

        return MeetingMessageResource::collection($messages);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function legacySendMessage(Meeting $meeting, MeetingParticipant $participant, array $validated): JsonResponse
    {
        /** @var array<UploadedFile> $files */
        $files = isset($validated['files']) && is_array($validated['files']) ? $validated['files'] : [];

        $replyToId = null;
        $replyToRaw = $validated['reply_to'] ?? null;
        if (is_numeric($replyToRaw)) {
            $replyToId = MeetingMessage::where('meeting_id', $meeting->id)->whereKey((int) $replyToRaw)->value('id');
        } elseif (is_string($replyToRaw) && $replyToRaw !== '' && strlen($replyToRaw) === 26) {
            $replyToId = MeetingMessage::where('meeting_id', $meeting->id)->where('public_id', $replyToRaw)->value('id');
        }

        $threadRootId = null;
        if ($replyToId) {
            $replyTarget = MeetingMessage::where('meeting_id', $meeting->id)
                ->find($replyToId, ['id', 'thread_root_message_id']);
            $threadRootId = $replyTarget?->thread_root_message_id ?: $replyTarget?->id;
        }

        $message = DB::transaction(function () use ($meeting, $participant, $validated, $replyToId, $threadRootId, $files) {
            $created = MeetingMessage::create([
                'meeting_id' => $meeting->id,
                'participant_public_id' => $participant->public_id,
                'body' => strip_tags((string) ($validated['body'] ?? '')),
                'temp_id' => isset($validated['temp_id']) ? (string) $validated['temp_id'] : null,
                'metadata' => isset($validated['metadata']) && is_array($validated['metadata']) ? $validated['metadata'] : null,
                'reply_to_message_id' => $replyToId,
                'thread_root_message_id' => $threadRootId,
            ]);

            if (! empty($files)) {
                app(MeetingChatMediaService::class)->attachFilesToMessage($created, $files);
            }

            return $created;
        });

        $message->load('media');

        broadcast(new MeetingSignal(
            $meeting,
            $message->participant_public_id,
            'chat-message',
            [
                'id' => $message->id,
                'public_id' => $message->public_id,
                'participant_public_id' => $message->participant_public_id,
                'participant_name' => $participant->display_name,
                'body' => $message->body,
                'temp_id' => $message->temp_id,
                'metadata' => $message->metadata,
                'attachments' => $message->toAttachmentPayload(),
                'reply_to_id' => $message->reply_to_message_id,
                'thread_root_id' => $message->thread_root_message_id,
                'is_pinned' => (bool) $message->is_pinned,
                'pinned_at' => $message->pinned_at?->toIso8601String(),
                'pinned_by_participant_public_id' => $message->pinned_by_participant_public_id,
                'created_at' => $message->created_at?->toIso8601String(),
            ]
        ));

        return response()->json([
            'data' => (new MeetingMessageResource(
                $message->load([
                    'participant:id,user_id,public_id,metadata',
                    'participant.user:id,name',
                    'media',
                    'replyTo:id,participant_public_id,body,created_at',
                    'threadRoot:id,participant_public_id,body,created_at',
                    'pinnedByParticipant:id,user_id,public_id,metadata',
                    'pinnedByParticipant.user:id,name',
                ])->loadCount('replies')
            ))->toArray(request()),
        ], 201);
    }

    protected function legacyPinMessage(Meeting $meeting, MeetingParticipant $actor, string $messageId): JsonResponse
    {
        $message = $this->resolveMeetingMessage($meeting, $messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $message->forceFill([
            'is_pinned' => true,
            'pinned_at' => now(),
            'pinned_by_participant_public_id' => $actor->public_id,
        ])->save();

        $payload = $this->serializeMeetingMessage($message);

        broadcast(new MeetingSignal(
            $meeting,
            $actor->public_id,
            'chat-message-pinned',
            ['message' => $payload]
        ));

        return response()->json(['data' => $payload]);
    }

    protected function legacyUnpinMessage(Meeting $meeting, MeetingParticipant $actor, string $messageId): JsonResponse
    {
        $message = $this->resolveMeetingMessage($meeting, $messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $message->forceFill([
            'is_pinned' => false,
            'pinned_at' => null,
            'pinned_by_participant_public_id' => null,
        ])->save();

        $payload = $this->serializeMeetingMessage($message);

        broadcast(new MeetingSignal(
            $meeting,
            $actor->public_id,
            'chat-message-unpinned',
            ['message' => $payload]
        ));

        return response()->json(['data' => $payload]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function legacyUpdateMessage(Meeting $meeting, MeetingParticipant $actor, string $messageId, array $validated): JsonResponse
    {
        $message = $this->resolveMeetingMessage($meeting, $messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        if (! $this->canEditChatMessage($actor, $message)) {
            return response()->json(['message' => 'Only the original sender can edit this message.'], 403);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            return response()->json(['message' => 'Deleted messages cannot be edited.'], 422);
        }

        $metadata['is_edited'] = true;
        $metadata['edited_at'] = now()->toIso8601String();
        $metadata['edited_by_participant_public_id'] = $actor->public_id;

        $message->forceFill([
            'body' => trim(strip_tags((string) ($validated['body'] ?? ''))),
            'metadata' => $metadata,
        ])->save();

        $payload = $this->serializeMeetingMessage($message);

        broadcast(new MeetingSignal(
            $meeting,
            $actor->public_id,
            'chat-message-edited',
            ['message' => $payload]
        ));

        return response()->json(['data' => $payload]);
    }

    protected function legacyDeleteMessage(Meeting $meeting, MeetingParticipant $actor, string $messageId): JsonResponse
    {
        $message = $this->resolveMeetingMessage($meeting, $messageId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        if (! $this->canDeleteChatMessage($actor, $message)) {
            return response()->json(['message' => 'Only the sender, host, or co-host can delete this message.'], 403);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) !== true) {
            $metadata['is_deleted'] = true;
            $metadata['deleted_at'] = now()->toIso8601String();
            $metadata['deleted_by_participant_public_id'] = $actor->public_id;
            unset($metadata['is_edited'], $metadata['edited_at'], $metadata['edited_by_participant_public_id']);

            $message->forceFill([
                'body' => '',
                'metadata' => $metadata,
                'is_pinned' => false,
                'pinned_at' => null,
                'pinned_by_participant_public_id' => null,
            ])->save();

            $message->clearMediaCollection(MeetingMessage::MEDIA_COLLECTION);
        }

        $payload = $this->serializeMeetingMessage($message);

        broadcast(new MeetingSignal(
            $meeting,
            $actor->public_id,
            'chat-message-deleted',
            ['message' => $payload]
        ));

        return response()->json(['data' => $payload]);
    }

    public function lock(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host

        Cache::put("meeting:lock:{$meeting->public_id}", true, 180); // 3 mins TTL

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-locked',
            ['is_locked' => true]
        ));

        return response()->json(['message' => 'Meeting locked.']);
    }

    public function unlock(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host

        Cache::forget("meeting:lock:{$meeting->public_id}");

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-locked',
            ['is_locked' => false]
        ));

        return response()->json(['message' => 'Meeting unlocked.']);
    }

    public function renewLock(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host

        Cache::put("meeting:lock:{$meeting->public_id}", true, 180); // 3 mins TTL

        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-locked',
            ['is_locked' => true]
        ));

        return response()->json(['message' => 'Meeting lock renewed.']);
    }

    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host

        $uniqueCount = $meeting->participants()->count();

        $meeting->update([
            'status' => 'ended',
            'actual_end_time' => $meeting->actual_end_time ?? now(),
            'unique_participant_count' => $uniqueCount,
            // We retain the peak_participant_count calculated during the meeting
        ]);
        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-ended',
            ['ended_by' => $hostParticipant?->public_id ?? 'system']
        ));

        broadcast(new \App\Events\Meetings\MeetingStatusUpdated($meeting));

        return response()->json(['message' => 'Meeting ended.']);
    }

    // ──── Polls ────────────────────────────────────────────────────────────────

    public function createPoll(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || ! $meeting->isHost($participant)) {
            return response()->json(['message' => 'Only the host can create polls.'], 403);
        }

        $validated = $request->validate([
            'question' => 'required|string|max:500',
            'options' => 'required|array|min:2|max:6',
            'options.*' => 'required|string|max:200',
            'allow_multiple' => 'boolean',
            'allow_change_vote' => 'boolean',
            'anonymous' => 'boolean',
        ]);

        $poll = \App\Models\MeetingPoll::create([
            'meeting_id' => $meeting->id,
            'created_by' => $participant->id,
            'question' => $validated['question'],
            'options' => array_values($validated['options']),
            'allow_multiple' => $validated['allow_multiple'] ?? false,
            'allow_change_vote' => $validated['allow_change_vote'] ?? false,
            'anonymous' => $validated['anonymous'] ?? false,
        ]);

        broadcast(new \App\Events\Meetings\MeetingPollCreated($meeting, $poll));

        return response()->json(['data' => [
            'public_id' => $poll->public_id,
            'question' => $poll->question,
            'options' => $poll->options,
            'vote_counts' => array_fill(0, count($poll->options), 0),
            'allow_multiple' => $poll->allow_multiple,
            'allow_change_vote' => $poll->allow_change_vote,
            'anonymous' => $poll->anonymous,
            'is_active' => true,
        ]], 201);
    }

    public function updatePoll(Request $request, Meeting $meeting, \App\Models\MeetingPoll $poll): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || ! $meeting->isHost($participant)) {
            return response()->json(['message' => 'Only the host can edit polls.'], 403);
        }

        if ($poll->votes()->count() > 0) {
            return response()->json(['message' => 'Cannot edit a poll that already has votes.'], 422);
        }

        $validated = $request->validate([
            'question' => 'required|string|max:500',
            'options' => 'required|array|min:2|max:6',
            'options.*' => 'required|string|max:200',
            'allow_multiple' => 'boolean',
            'allow_change_vote' => 'boolean',
            'anonymous' => 'boolean',
        ]);

        $poll->update([
            'question' => $validated['question'],
            'options' => array_values($validated['options']),
            'allow_multiple' => $validated['allow_multiple'] ?? $poll->allow_multiple,
            'allow_change_vote' => $validated['allow_change_vote'] ?? $poll->allow_change_vote,
            'anonymous' => $validated['anonymous'] ?? $poll->anonymous,
        ]);

        // Broadcast a custom event for poll update if needed, but for now we just return
        // Usually, the frontend can just rely on the host's update or we can broadcast MeetingPollCreated again?
        // Let's create a MeetingPollUpdated event for better UX.
        // For now, let's just broadcast MeetingPollCreated again, it should overwrite on frontend.
        broadcast(new \App\Events\Meetings\MeetingPollCreated($meeting, $poll));

        return response()->json(['message' => 'Poll updated.']);
    }

    public function deletePoll(Request $request, Meeting $meeting, \App\Models\MeetingPoll $poll): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || ! $meeting->isHost($participant)) {
            return response()->json(['message' => 'Only the host can delete polls.'], 403);
        }

        $poll->delete();

        // Broadcast MeetingPollDeleted event
        broadcast(new \App\Events\Meetings\MeetingPollDeleted($meeting, $poll->public_id));

        return response()->json(['message' => 'Poll deleted.']);
    }

    public function votePoll(Request $request, Meeting $meeting, \App\Models\MeetingPoll $poll): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'You must be admitted to vote.'], 403);
        }

        if (! $poll->is_active) {
            return response()->json(['message' => 'This poll has ended.'], 422);
        }

        $validated = $request->validate([
            'option_indexes' => 'required|array|min:1',
            'option_indexes.*' => 'required|integer|min:0',
        ]);

        $indexes = array_unique($validated['option_indexes']);

        // Validate all indexes are within range
        foreach ($indexes as $idx) {
            if ($idx >= count($poll->options)) {
                return response()->json(['message' => "Invalid option index: $idx"], 422);
            }
        }

        if (! $poll->allow_multiple && count($indexes) > 1) {
            return response()->json(['message' => 'Multiple select is not allowed for this poll.'], 422);
        }

        \DB::transaction(function () use ($poll, $participant, $indexes) {
            if ($poll->allow_change_vote) {
                // Remove existing votes before applying new ones
                $poll->votes()->where('participant_id', $participant->id)->delete();
            }

            foreach ($indexes as $idx) {
                try {
                    \App\Models\MeetingPollVote::create([
                        'poll_id' => $poll->id,
                        'participant_id' => $participant->id,
                        'option_index' => $idx,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Unique constraint: already voted for this option
                    // If allow_change_vote is false, this will block re-voting
                    if (! $poll->allow_change_vote) {
                        throw $e;
                    }
                }
            }
        });

        broadcast(new \App\Events\Meetings\MeetingPollVoted($meeting, $poll));

        return response()->json(['message' => 'Vote recorded.']);
    }

    public function endPoll(Request $request, Meeting $meeting, \App\Models\MeetingPoll $poll): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || ! $meeting->isHost($participant)) {
            return response()->json(['message' => 'Only the host can end polls.'], 403);
        }

        $poll->update(['is_active' => false, 'ended_at' => now()]);

        broadcast(new \App\Events\Meetings\MeetingPollEnded($meeting, $poll));

        return response()->json(['message' => 'Poll ended.']);
    }

    public function getPolls(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);

        $polls = $meeting->polls()
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn ($p) => [
                'public_id' => $p->public_id,
                'question' => $p->question,
                'options' => $p->options,
                'is_active' => $p->is_active,
                'allow_multiple' => (bool) $p->allow_multiple,
                'allow_change_vote' => (bool) $p->allow_change_vote,
                'anonymous' => (bool) $p->anonymous,
                'vote_counts' => $p->getVoteCounts(),
                'voter_count' => $p->getVoterCount(),
                'my_votes' => $participant ? $p->votes()->where('participant_id', $participant->id)->pluck('option_index')->toArray() : [],
            ]);

        return response()->json(['data' => $polls]);
    }

    // ──── Laser Pointer ────────────────────────────────────────────────────────

    public function laserMove(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant || $participant->status !== 'admitted') {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $settings = $meeting->settings ?? [];
        $mode = $settings['laser_pointer_mode'] ?? 'off';

        if ($mode === 'off') {
            return response()->json(['message' => 'Laser pointer is disabled.'], 403);
        }

        if ($mode === 'targeted') {
            $allowedId = $settings['laser_pointer_participant_id'] ?? null;
            if ($allowedId !== $participant->public_id) {
                return response()->json(['message' => 'Laser pointer not enabled for you.'], 403);
            }
        }

        $validated = $request->validate([
            'x' => 'required|numeric|min:0|max:100',
            'y' => 'required|numeric|min:0|max:100',
        ]);

        broadcast(new \App\Events\Meetings\MeetingLaserPointerMoved(
            $meeting->public_id,
            $participant->public_id,
            (float) $validated['x'],
            (float) $validated['y'],
        ));

        return response()->json(['ok' => true]);
    }

    // ──── Breakout Rooms ───────────────────────────────────────────────────────

    public function startBreakout(Request $request, Meeting $meeting): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('Start breakout request', [
            'meeting' => $meeting->public_id,
            'payload' => $request->all(),
        ]);

        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'rooms' => 'required|array',
            'duration_minutes' => 'nullable|integer|min:1|max:180',
        ]);

        $this->meetingService->startBreakout(
            $meeting,
            $validated['rooms'],
            $validated['duration_minutes'] ?? null
        );

        return response()->json(['message' => 'Breakout session started.']);
    }

    public function endBreakout(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $this->meetingService->endBreakout($meeting);

        return response()->json(['message' => 'Breakout session ended.']);
    }

    public function joinBreakoutRoom(Request $request, Meeting $meeting, string $room): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $normalizedRoomId = in_array(strtolower($room), ['main', 'null', ''], true) ? null : $room;

        $this->meetingService->joinBreakoutRoom($meeting, $participant, $normalizedRoomId);

        return response()->json(['message' => 'Joined room.']);
    }

    public function requestBreakoutHelp(Request $request, Meeting $meeting, string $room): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->meetingService->requestBreakoutHelp($meeting, $room);

        return response()->json(['message' => 'Help requested.']);
    }

    public function moveParticipantToBreakout(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'participant_public_id' => 'required|string',
            'target_room_id' => 'nullable|string',
        ]);

        $this->meetingService->moveParticipantToBreakout(
            $meeting,
            $validated['participant_public_id'],
            $validated['target_room_id']
        );

        return response()->json(['message' => 'Participant move triggered.']);
    }

    public function updateBreakoutTimer(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $validated = $request->validate([
            'additional_minutes' => 'required|integer|min:-30|max:30|not_in:0',
        ]);

        $this->meetingService->updateBreakoutTimer($meeting, $validated['additional_minutes']);

        return response()->json(['message' => 'Breakout timer updated.']);
    }

    public function notifyBreakoutActivity(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (! $participant) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:200',
            'target_room_id' => 'nullable|string',
        ]);

        $this->meetingService->notifyBreakoutActivity(
            $meeting,
            $validated['message'],
            $validated['target_room_id']
        );

        return response()->json(['message' => 'Activity notification sent.']);
    }

    protected function isChatModerator(MeetingParticipant $participant): bool
    {
        return in_array($participant->role, ['host', 'co-host'], true);
    }

    protected function canEditChatMessage(MeetingParticipant $actor, MeetingMessage $message): bool
    {
        return strtolower((string) $actor->public_id) === strtolower((string) $message->participant_public_id);
    }

    protected function canDeleteChatMessage(MeetingParticipant $actor, MeetingMessage $message): bool
    {
        return $this->canEditChatMessage($actor, $message) || $this->isChatModerator($actor);
    }

    protected function resolveMeetingMessage(Meeting $meeting, string $messageId): ?MeetingMessage
    {
        $query = MeetingMessage::where('meeting_id', $meeting->id);

        if (ctype_digit($messageId)) {
            return (clone $query)->whereKey((int) $messageId)->first();
        }

        if (strlen($messageId) === 26) {
            return (clone $query)->whereRaw('LOWER(public_id) = ?', [strtolower($messageId)])->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMeetingMessage(MeetingMessage $message): array
    {
        $resource = new MeetingMessageResource(
            $message->load([
                'participant:id,user_id,public_id,metadata',
                'participant.user:id,name',
                'media',
                'replyTo:id,participant_public_id,body,created_at',
                'threadRoot:id,participant_public_id,body,created_at',
                'pinnedByParticipant:id,user_id,public_id,metadata',
                'pinnedByParticipant.user:id,name',
            ])->loadCount('replies')
        );

        return $resource->resolve();
    }

    private function meetingResponse(Meeting $meeting, ?string $plainPassword = null): JsonResponse
    {
        $payload = (new MeetingResource($meeting->load(['host', 'participants.user'])))->resolve();

        if (is_string($plainPassword) && $plainPassword !== '') {
            $payload['plain_password'] = $plainPassword;
        }

        return response()->json($payload);
    }

    // ──── Helper ───────────────────────────────────────────────────────────────

    /**
     * Resolve the current request's MeetingParticipant by auth user or
     * guest HttpOnly cookie/session identity.
     */
    private function resolveParticipant(Request $request, Meeting $meeting): ?\App\Models\MeetingParticipant
    {
        if ($user = $request->user()) {
            return $meeting->participants()->where('user_id', $user->id)->first();
        }

        $effectivePid = MeetingParticipantSession::resolveGuestParticipantId($request, $meeting);
        if (! $effectivePid) {
            return null;
        }

        return $meeting->participants()
            ->whereRaw('LOWER(public_id) = ?', [strtolower($effectivePid)])
            ->first();
    }

    private function meetingParticipantCookie(Request $request, Meeting $meeting, string $participantPublicId): Cookie
    {
        $minutes = max(60, (int) config('session.lifetime', 120));
        $cookieValue = MeetingParticipantSession::buildCookieValue($request, $meeting, $participantPublicId);

        return cookie(
            MeetingParticipantSession::cookieName(),
            $cookieValue,
            $minutes,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        );
    }
}
