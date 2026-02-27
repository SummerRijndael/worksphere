<?php

namespace App\Http\Controllers\Api;

use App\Contracts\MeetingServiceContract;
use App\Events\Meetings\MeetingSignal;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\MeetingMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MeetingController extends Controller
{
    public function __construct(
        protected MeetingServiceContract $meetingService
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

    public function store(Request $request): MeetingResource
    {
        $this->authorize('create', Meeting::class);

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

        $data = $request->only(['title', 'description', 'start_time', 'end_time', 'settings']);
        $data['password'] = $password;

        $meeting = $this->meetingService->createMeeting($request->user(), $data);

        return new MeetingResource($meeting->load(['host', 'participants.user']));
    }

    public function show(Meeting $meeting): MeetingResource
    {
        $this->authorize('view', $meeting);
        return new MeetingResource($meeting->load(['host', 'participants.user']));
    }

    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['email' => 'nullable|email']);

        $participantSessionId = session('meeting_participant_id');

        try {
            $result = $this->meetingService->joinMeeting(
                $meeting, 
                $request->user(),
                $request->input('name', 'Guest'),
                $request->input('email'),
                $request->input('password'),
                $participantSessionId
            );

            if (!$request->user()) {
                session(['meeting_participant_id' => $result['participant']->public_id]);
            }

            return response()->json([
                'data' => [
                    'meeting' => new MeetingResource($result['meeting']),
                    'participant' => $result['participant'],
                ]
            ]);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'REQUIRES_PASSWORD')) {
                return response()->json([
                    'message' => 'Invalid meeting password.',
                    'requires_password' => true
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

        if (!preg_match('/^meeting\.([a-zA-Z0-9_-]+)$/', $actualChannel, $matches)) {
            return response()->json(['message' => 'Invalid meeting channel'], 403);
        }

        $meeting = Meeting::where('public_id', $matches[1])->first();
        if (!$meeting) return response()->json(['message' => 'Meeting not found'], 404);

        $participantSessionId = $request->header('X-Participant-ID') ?: (session('meeting_participant_id') ?: session('participant_id'));

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
        $request->validate([
            'signal_type' => 'required|string',
            'signal_data' => 'present|array',
            'target_participant_public_id' => 'nullable|string',
            'sender_participant_public_id' => 'required|string',
        ]);

        $sender = MeetingParticipant::where('meeting_id', $meeting->id)
            ->whereRaw('LOWER(public_id) = ?', [strtolower($request->sender_participant_public_id)])
            ->where('status', 'admitted')
            ->first();

        if (!$sender) {
            return response()->json(['message' => 'Unauthorized or not admitted'], 403);
        }

        broadcast(new MeetingSignal(
            $meeting,
            $sender->public_id,
            $request->signal_type,
            $request->signal_data,
            $request->target_participant_public_id
        ))->toOthers();
        
        return response()->json(['status' => 'ok']);
    }

    public function update(Request $request, Meeting $meeting): MeetingResource
    {
        $this->authorize('update', $meeting);

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

        $meeting = $this->meetingService->updateMeeting($meeting, $data);

        return new MeetingResource($meeting->load(['host', 'participants.user']));
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
        $appId = config('services.cloudflare.app_id');
        $secret = config('services.cloudflare.app_secret');

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->post("https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/tracks/new", $request->only(['sessionDescription', 'tracks']));

            $responseData = $response->json();
            if (! $response->successful()) {
                Log::channel('videocall')->error('[SFU] Meeting Cloudflare tracks/new error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
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

        try {
            $response = Http::withToken($secret)
                ->timeout(60)
                ->send($method, "https://rtc.live.cloudflare.com/v1/apps/{$appId}/sessions/{$sessionId}/renegotiate", [
                    'json' => ! empty($data) ? $data : null,
                ]);

            $responseData = $response->json();
            if (! $response->successful()) return response()->json(['error' => 'Renegotiation Error'], $response->status());
            
            return response()->json($responseData, $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'SFU Renegotiation Exception', 'details' => $e->getMessage()], 500);
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

        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);

        $this->meetingService->rejectParticipant($meeting, $participant);
        return response()->json(['message' => 'Participant rejected.']);
    }

    public function mute(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);
        
        $participant->update(['is_muted_by_host' => true]);
        return response()->json(['message' => 'Participant muted.']);
    }

    public function unmute(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);
        
        $participant->update(['is_muted_by_host' => false]);
        return response()->json(['message' => 'Participant can unmute.']);
    }

    public function cameraOff(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);

        $participant->update(['is_camera_disabled_by_host' => true]);
        return response()->json(['message' => 'Participant camera disabled.']);
    }

    public function cameraAllow(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);

        $participant->update(['is_camera_disabled_by_host' => false]);
        return response()->json(['message' => 'Participant camera allowed.']);
    }

    public function kick(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('moderate', $meeting);
        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);

        $participant->update(['status' => 'rejected']);
        return response()->json(['message' => 'Participant kicked.']);
    }

    public function promote(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host
        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);

        $participant = $this->meetingService->promoteParticipant($meeting, $participant);
        return response()->json(['message' => 'Participant promoted to co-host.', 'participant' => $participant]);
    }

    public function demote(Request $request, Meeting $meeting, MeetingParticipant $participant): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host
        if ($participant->meeting_id !== $meeting->id) return response()->json(['message' => 'Not found'], 404);

        $participant = $this->meetingService->demoteParticipant($meeting, $participant);
        return response()->json(['message' => 'Participant demoted.', 'participant' => $participant]);
    }

    public function getMessages(Request $request, Meeting $meeting): AnonymousResourceCollection
    {
        $messages = MeetingMessage::where('meeting_id', $meeting->id)->orderBy('created_at', 'asc')->get();
        return \App\Http\Resources\MeetingMessageResource::collection($messages);
    }

    public function sendMessage(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate([
            'participant_public_id' => 'required|string',
            'body' => 'required|string|max:2000',
        ]);

        $key = "meeting_chat_" . strtolower($request->participant_public_id);
        $count = (int) Cache::get($key, 0);

        if ($count >= 20) return response()->json(['message' => 'Too many messages.'], 429);
        Cache::put($key, $count + 1, 60);

        $message = MeetingMessage::create([
            'meeting_id' => $meeting->id,
            'participant_public_id' => $request->participant_public_id,
            'body' => strip_tags($request->body),
        ]);

        broadcast(new MeetingSignal(
            $meeting,
            $message->participant_public_id,
            'chat-message',
            [
                'id' => $message->id,
                'participant_public_id' => $message->participant_public_id,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ]
        ));

        return response()->json(new \App\Http\Resources\MeetingMessageResource($message), 201);
    }

    public function lock(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host

        $meeting->update(['is_locked' => true]);
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

        $meeting->update(['is_locked' => false]);
        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-locked',
            ['is_locked' => false]
        ));

        return response()->json(['message' => 'Meeting unlocked.']);
    }

    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting); // Only host

        $meeting->update(['status' => 'ended']);
        $hostParticipant = $meeting->participants()->where('user_id', Auth::id())->first();

        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'meeting-ended',
            ['ended_by' => $hostParticipant?->public_id ?? 'system']
        ));

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
            'options'  => 'required|array|min:2|max:6',
            'options.*' => 'required|string|max:200',
            'allow_multiple' => 'boolean',
            'allow_change_vote' => 'boolean',
            'anonymous' => 'boolean',
        ]);

        $poll = \App\Models\MeetingPoll::create([
            'meeting_id'  => $meeting->id,
            'created_by'  => $participant->id,
            'question'    => $validated['question'],
            'options'     => array_values($validated['options']),
            'allow_multiple' => $validated['allow_multiple'] ?? false,
            'allow_change_vote' => $validated['allow_change_vote'] ?? false,
            'anonymous' => $validated['anonymous'] ?? false,
        ]);

        broadcast(new \App\Events\Meetings\MeetingPollCreated($meeting, $poll));

        return response()->json(['data' => [
            'public_id'   => $poll->public_id,
            'question'    => $poll->question,
            'options'     => $poll->options,
            'vote_counts' => array_fill(0, count($poll->options), 0),
            'allow_multiple' => $poll->allow_multiple,
            'allow_change_vote' => $poll->allow_change_vote,
            'anonymous' => $poll->anonymous,
            'is_active'   => true,
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
            'options'  => 'required|array|min:2|max:6',
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
            'option_indexes.*' => 'required|integer|min:0'
        ]);

        $indexes = array_unique($validated['option_indexes']);
        
        // Validate all indexes are within range
        foreach ($indexes as $idx) {
            if ($idx >= count($poll->options)) {
                return response()->json(['message' => "Invalid option index: $idx"], 422);
            }
        }

        if (!$poll->allow_multiple && count($indexes) > 1) {
            return response()->json(['message' => 'Multiple select is not allowed for this poll.'], 422);
        }

        \DB::transaction(function() use ($poll, $participant, $indexes) {
            if ($poll->allow_change_vote) {
                // Remove existing votes before applying new ones
                $poll->votes()->where('participant_id', $participant->id)->delete();
            }

            foreach ($indexes as $idx) {
                try {
                    \App\Models\MeetingPollVote::create([
                        'poll_id'       => $poll->id,
                        'participant_id' => $participant->id,
                        'option_index'  => $idx,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Unique constraint: already voted for this option
                    // If allow_change_vote is false, this will block re-voting
                    if (!$poll->allow_change_vote) {
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
        $polls = $meeting->polls()
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn($p) => [
                'public_id'   => $p->public_id,
                'question'    => $p->question,
                'options'     => $p->options,
                'is_active'   => $p->is_active,
                'vote_counts' => $p->getVoteCounts(),
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
            'duration_minutes' => 'nullable|integer',
        ]);

        $this->meetingService->startBreakout($meeting, $validated['rooms'], $validated['duration_minutes']);

        return response()->json(['message' => 'Breakout session started.']);
    }

    public function endBreakout(Request $request, Meeting $meeting): JsonResponse
    {
        $this->authorize('update', $meeting);

        $this->meetingService->endBreakout($meeting);

        return response()->json(['message' => 'Breakout session ended.']);
    }

    public function joinBreakoutRoom(Request $request, Meeting $meeting, string $roomId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (!$participant) return response()->json(['message' => 'Unauthorized'], 403);

        $this->meetingService->joinBreakoutRoom($meeting, $participant, $roomId);
        
        return response()->json(['message' => 'Joined room.']);
    }

    public function requestBreakoutHelp(Request $request, Meeting $meeting, string $roomId): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (!$participant) return response()->json(['message' => 'Unauthorized'], 403);

        $this->meetingService->requestBreakoutHelp($meeting, $roomId);

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
            'additional_minutes' => 'required|integer',
        ]);

        $this->meetingService->updateBreakoutTimer($meeting, $validated['additional_minutes']);

        return response()->json(['message' => 'Breakout timer updated.']);
    }

    public function notifyBreakoutActivity(Request $request, Meeting $meeting): JsonResponse
    {
        $participant = $this->resolveParticipant($request, $meeting);
        if (!$participant) return response()->json(['message' => 'Unauthorized'], 403);

        $validated = $request->validate([
            'message' => 'required|string|max:255',
            'target_room_id' => 'nullable|string',
        ]);

        $this->meetingService->notifyBreakoutActivity(
            $meeting,
            $validated['message'],
            $validated['target_room_id']
        );

        return response()->json(['message' => 'Activity notification sent.']);
    }

    // ──── Helper ───────────────────────────────────────────────────────────────

    /**
     * Resolve the current request's MeetingParticipant by auth user or
     * the X-Participant-ID header (for guest rejoin tokens).
     */
    private function resolveParticipant(Request $request, Meeting $meeting): ?\App\Models\MeetingParticipant
    {
        if ($user = $request->user()) {
            return $meeting->participants()->where('user_id', $user->id)->first();
        }
        $pid = $request->header('X-Participant-ID');
        if ($pid) {
            return $meeting->participants()
                ->whereRaw('LOWER(public_id) = ?', [strtolower($pid)])
                ->first();
        }
        return null;
    }
}
