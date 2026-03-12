<?php

namespace App\Http\Controllers\Api;

use App\Events\Meetings\MeetingSignal;
use App\Jobs\IngestMeetingRecordingMedia;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Services\CloudflareRealtimeKitService;
use App\Support\MeetingParticipantSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Handles meeting recording lifecycle for PRO users.
 *
 * Requires MEETING_RECORDING_ENABLED=true (dev toggle).
 * The recording is done server-side by a Cloudflare RealtimeKit virtual bot.
 * The host controls start/stop; all participants see the REC badge via broadcast.
 */
class MeetingRecordingController extends Controller
{
    public function __construct(
        private CloudflareRealtimeKitService $realtime
    ) {}

    // ─── Guards ───────────────────────────────────────────────────────────────

    /**
     * Shared guard: recording must be enabled, meeting must exist, caller must be host/moderator.
     */
    private function resolveAuthorizedMeeting(string $meetingId): Meeting
    {
        abort_unless(
            config('services.cloudflare_realtime.recording_enabled', false),
            403,
            'Meeting recording is not enabled.'
        );

        $meeting = Meeting::where('public_id', $meetingId)->firstOrFail();

        // Only the meeting host (user_id) may control recordings
        abort_unless(
            Auth::id() === $meeting->user_id,
            403,
            'Only the meeting host can control recordings.'
        );

        return $meeting;
    }

    // ─── Join Token (PRO meeting bootstrap) ──────────────────────────────────

    /**
     * POST /api/meetings/{meeting}/recording/token
     *
     * Called when a host or participant enters a meeting that has recording enabled.
     * Ensures a RealtimeKit meeting exists for this session, then adds the user as
     * a participant and returns an auth_token for the frontend SDK.
     *
     * Free-user meetings don't call this — they use the existing SFU path.
     */
    public function token(Request $request, string $meetingId): JsonResponse
    {
        abort_unless(
            config('services.cloudflare_realtime.recording_enabled', false),
            403,
            'Meeting recording is not enabled.'
        );

        $meeting = Meeting::where('public_id', $meetingId)->firstOrFail();

        // Ensure a Cloudflare RealtimeKit meeting exists for this session
        if (! $meeting->cf_meeting_id) {
            try {
                $cfMeeting = $this->realtime->createMeeting([
                    'title' => $meeting->title ?? "Meeting {$meetingId}",
                ]);
                // Cloudflare V4 API returns data in 'result' key
                $cfId = $cfMeeting['result']['id'] ?? $cfMeeting['id'] ?? $cfMeeting['data']['id'] ?? null;
                $meeting->update(['cf_meeting_id' => $cfId]);
            } catch (\Throwable $e) {
                Log::channel('cloudflare_realtime')->error('Failed to create RealtimeKit meeting', ['error' => $e->getMessage(), 'meeting' => $meetingId]);

                return response()->json(['error' => 'Failed to create recording session.'], 500);
            }
        }

        // Determine participant role
        $user = Auth::user();
        $isHost = $user && $user->id === $meeting->user_id;
        $preset = $isHost ? 'group_call_host' : 'group_call_participant';

        // Identify the actual participant record for this session
        $participantId = null;
        if (! $user) {
            $participantId = MeetingParticipantSession::resolveGuestParticipantId($request, $meeting);
            abort_unless($participantId, 403, 'Invalid meeting participant session.');
        }

        $participantRecord = \App\Models\MeetingParticipant::where('meeting_id', $meeting->id);

        if ($user) {
            $participantRecord->where('user_id', $user->id);
        } else {
            $participantRecord->where('public_id', $participantId);
        }
        $participantRecord = $participantRecord->first();

        try {
            $participant = $this->realtime->addParticipant($meeting->cf_meeting_id, [
                'name' => $user?->name ?? ($participantRecord->metadata['guest_name'] ?? 'Guest'),
                'preset_name' => $preset,
                'custom_participant_id' => $participantRecord?->public_id ?? ($user?->public_id ?? 'guest-'.uniqid()),
            ]);

            // --- STUCK RECORDING CLEANUP ---
            // If a recording is stuck in 'recording' status for too long (e.g. > 4 hours),
            // we should probably consider it dead and avoid showing it to new joiners.
            $active = $meeting->activeRecording;
            if ($active && $active->status === 'recording' && $active->created_at->diffInHours() > 4) {
                Log::channel('cloudflare_realtime')->info('Marking stuck recording as failed', ['recording_id' => $active->id]);
                $active->update(['status' => 'failed']);
                $active = null;
            }

            return response()->json([
                'cf_meeting_id' => $meeting->cf_meeting_id,
                'auth_token' => $participant['result']['authToken'] ?? $participant['result']['token'] ?? $participant['data']['token'] ?? $participant['token'] ?? null,
                'recording' => $active ? [
                    'id' => $active->id,
                    'status' => $active->status,
                    'started_at' => $active->created_at->toISOString(),
                ] : null,
            ]);
        } catch (\Throwable $e) {
            Log::channel('cloudflare_realtime')->error('Failed to add RealtimeKit participant', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to join recording session.'], 500);
        }
    }

    // ─── Recording Control ────────────────────────────────────────────────────

    /**
     * POST /api/meetings/{meeting}/recording/start
     *
     * Starts a server-side recording. The CloudFlare bot joins the RealtimeKit
     * meeting and records audio+video of all participants into a composite file.
     *
     * Broadcasts `recording-started` signal so all clients show the REC badge.
     */
    public function start(string $meetingId): JsonResponse
    {
        $meeting = $this->resolveAuthorizedMeeting($meetingId);

        // Can't start without a RealtimeKit meeting
        abort_unless($meeting->cf_meeting_id, 422, 'No RealtimeKit session found for this meeting. Join first.');

        // Prevent double-recording
        if ($meeting->activeRecording()->exists()) {
            return response()->json(['error' => 'A recording is already in progress.'], 409);
        }

        try {
            $cfRecording = $this->realtime->startRecording($meeting->cf_meeting_id);

            $cfRecordingId = $cfRecording['data']['id'] ?? $cfRecording['id'] ?? null;

            $recording = MeetingRecording::create([
                'meeting_id' => $meeting->id,
                'cf_meeting_id' => $meeting->cf_meeting_id,
                'cf_recording_id' => $cfRecordingId,
                'started_by' => Auth::id(),
                'status' => 'recording',
            ]);

            // Broadcast to all participants in the meeting room
            broadcast(new MeetingSignal($meeting, 'system', 'recording-started', [
                'recording_id' => $recording->id,
                'started_by' => Auth::user()?->name ?? 'Host',
            ]))->toOthers();

            return response()->json([
                'recording_id' => $recording->id,
                'cf_recording_id' => $cfRecordingId,
                'status' => 'recording',
            ]);
        } catch (\Throwable $e) {
            Log::channel('cloudflare_realtime')->error('Failed to start recording', ['error' => $e->getMessage(), 'meeting' => $meetingId]);

            return response()->json(['error' => 'Failed to start recording: '.$e->getMessage()], 500);
        }
    }

    /**
     * POST /api/meetings/{meeting}/recording/stop
     *
     * Stops the active recording. The Cloudflare bot leaves the meeting and
     * begins processing the recording file. The download_url is available once
     * Cloudflare marks the recording as `completed`.
     *
     * Broadcasts `recording-stopped` so all clients hide the REC badge.
     */
    public function stop(string $meetingId): JsonResponse
    {
        $meeting = $this->resolveAuthorizedMeeting($meetingId);

        $recording = $meeting->activeRecording()->first();
        abort_unless($recording, 404, 'No active recording found for this meeting.');

        try {
            if ($recording->cf_recording_id) {
                $cfResult = $this->realtime->stopRecording($recording->cf_recording_id);
                $recording->update(['status' => 'processing']);
            } else {
                // If we never got a CF id (e.g. pending), just mark as failed
                $recording->update(['status' => 'failed']);
            }

            broadcast(new MeetingSignal($meeting, 'system', 'recording-stopped', [
                'recording_id' => $recording->id,
            ]))->toOthers();

            return response()->json([
                'recording_id' => $recording->id,
                'status' => $recording->fresh()->status,
            ]);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();

            // Gracefully handle "already stopped" recordings
            if (str_contains($errorMessage, 'is not in progress') || str_contains($errorMessage, 'UPLOADED')) {
                $recording->update(['status' => 'processing']);

                broadcast(new MeetingSignal($meeting, 'system', 'recording-stopped', [
                    'recording_id' => $recording->id,
                ]))->toOthers();

                return response()->json([
                    'recording_id' => $recording->id,
                    'status' => 'processing',
                    'message' => 'Recording was already stopped.',
                ]);
            }

            Log::channel('cloudflare_realtime')->error('Failed to stop recording', ['error' => $errorMessage, 'meeting' => $meetingId]);

            return response()->json(['error' => 'Failed to stop recording: '.$errorMessage], 500);
        }
    }

    /**
     * POST /api/meetings/{meeting}/recording/force-stop
     *
     * Emergency cleanup: marks any active recording as failed/stopped in our DB
     * and attempts to stop it in Cloudflare. Use this if a recording is stuck.
     */
    public function forceStop(string $meetingId): JsonResponse
    {
        $meeting = $this->resolveAuthorizedMeeting($meetingId);
        $recording = $meeting->activeRecording()->first();

        if ($recording) {
            try {
                if ($recording->cf_recording_id) {
                    $this->realtime->stopRecording($recording->cf_recording_id);
                }
            } catch (\Throwable $e) {
                Log::channel('cloudflare_realtime')->warning('Force stop: Failed to stop on CF (maybe already dead)', ['error' => $e->getMessage()]);
            }
            $recording->update(['status' => 'failed']);
        }

        broadcast(new MeetingSignal($meeting, 'system', 'recording-stopped', [
            'recording_id' => $recording?->id,
        ]))->toOthers();

        return response()->json(['status' => 'reset']);
    }

    // ─── Listing ──────────────────────────────────────────────────────────────

    /**
     * GET /api/meetings/{meeting}/recordings
     * Returns all recordings for this meeting. Host-only.
     */
    public function index(string $meetingId): JsonResponse
    {
        $meeting = Meeting::where('public_id', $meetingId)->firstOrFail();
        \Illuminate\Support\Facades\Gate::authorize('view', $meeting);

        $recordings = $meeting->recordings()
            ->with(['startedBy:id,public_id,name', 'media'])
            ->latest()
            ->get()
            ->map(function ($r) {
                $media = $r->recording_media;
                $downloadUrl = $media
                    ? URL::temporarySignedRoute(
                        'api.media.secure-download',
                        now()->addMinutes(60),
                        ['media' => $media->id]
                    )
                    : $r->download_url;

                return [
                    'id' => $r->id,
                    'status' => $r->status,
                    'download_url' => $downloadUrl,
                    'cloudflare_download_url' => $r->download_url,
                    'display_name' => $r->display_name,
                    'duration_seconds' => $r->duration_seconds,
                    'size_bytes' => $media?->size,
                    'started_by' => $r->startedBy?->name,
                    'started_at' => $r->created_at?->toISOString(),
                    'created_at' => $r->created_at?->toISOString(),
                ];
            });

        return response()->json(['data' => $recordings]);
    }

    // ─── Webhook (optional: Cloudflare notifies when recording is ready) ──────

    /**
     * POST /api/webhooks/cloudflare/recording
     * Cloudflare calls this when a recording changes status (e.g. processing → completed).
     * Configure this URL in: dash.realtime.cloudflare.com → App → Webhooks
     */
    public function webhook(Request $request): JsonResponse
    {
        // Basic check — Cloudflare can't easily sign these, so we rely on obscurity
        // for now. You can add a shared secret header check here if Cloudflare adds one.
        $cfRecordingId = $request->input('recording_id') ?? $request->input('data.id');
        $status = $request->input('status') ?? $request->input('data.status');
        $downloadUrl = $request->input('download_url') ?? $request->input('data.download_url');
        $duration = $request->input('duration') ?? $request->input('data.duration');
        // R2 integration: Cloudflare might send storage info in metadata or custom fields
        $storagePath = $request->input('storage_path') ?? $request->input('data.storage_path');
        $storageDisk = $request->input('storage_disk') ?? $request->input('data.storage_disk') ?? ($storagePath ? 'private' : null);

        Log::channel('cloudflare_realtime')->info('Recording webhook received', [
            'recording_id' => $cfRecordingId,
            'status' => $status,
            'download_url' => $downloadUrl,
            'storage_path' => $storagePath,
            'duration' => $duration,
            'payload' => $request->all(),
        ]);

        if (! $cfRecordingId) {
            return response()->json(['ok' => false, 'error' => 'Missing recording_id'], 400);
        }

        $recording = MeetingRecording::where('cf_recording_id', $cfRecordingId)->first();

        if ($recording) {
            $recording->update(array_filter([
                'status' => $this->mapCfStatus($status),
                'download_url' => $downloadUrl,
                'storage_path' => $storagePath,
                'storage_disk' => $storageDisk,
                'duration_seconds' => $duration,
                'cf_metadata' => $request->all(),
            ]));

            $this->dispatchIngestionIfReady($recording->fresh());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/meetings/{meeting}/recordings/{recording}/sync
     * Manually syncs the recording status from Cloudflare.
     */
    public function sync(string $meetingId, string $recordingId): JsonResponse
    {
        $meeting = Meeting::where('public_id', $meetingId)->firstOrFail();
        \Illuminate\Support\Facades\Gate::authorize('view', $meeting);

        $recording = $meeting->recordings()->where('id', $recordingId)->firstOrFail();

        abort_unless($recording->cf_recording_id, 400, 'Recording has no Cloudflare ID.');

        try {
            $cfRecording = $this->realtime->getRecording($recording->cf_recording_id);

            // Log the raw result to help debug structure
            Log::channel('cloudflare_realtime')->debug('Manual sync result', ['result' => $cfRecording]);

            $status = $cfRecording['data']['status'] ?? $cfRecording['status'] ?? null;
            $downloadUrl = $cfRecording['data']['download_url'] ?? $cfRecording['download_url'] ?? null;
            $duration = $cfRecording['data']['duration'] ?? $cfRecording['duration'] ?? null;
            // R2 storage often shows up in a different field in the direct API response
            $storagePath = $cfRecording['data']['storage_path'] ?? $cfRecording['storage_path'] ?? null;

            if ($status) {
                $recording->update(array_filter([
                    'status' => $this->mapCfStatus($status),
                    'download_url' => $downloadUrl,
                    'storage_path' => $storagePath,
                    'duration_seconds' => $duration,
                    'cf_metadata' => $cfRecording,
                ]));
            }

            $this->dispatchIngestionIfReady($recording->fresh());

            return response()->json([
                'id' => $recording->id,
                'status' => $recording->status,
            ]);
        } catch (\Throwable $e) {
            Log::channel('cloudflare_realtime')->error('Failed to sync recording', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to sync with Cloudflare: '.$e->getMessage()], 500);
        }
    }

    private function mapCfStatus(?string $cfStatus): string
    {
        return match ($cfStatus) {
            'active', 'RECORDING' => 'recording',
            'processing', 'INVOKED', 'UPLOADING' => 'processing',
            'completed', 'UPLOADED' => 'completed',
            'failed', 'ERRORED' => 'failed',
            default => 'processing',
        };
    }

    private function dispatchIngestionIfReady(?MeetingRecording $recording): void
    {
        if (! config('services.cloudflare_realtime.store_locally', true)) {
            return;
        }

        if (! $recording) {
            return;
        }

        if ($recording->status !== 'completed' || blank($recording->download_url)) {
            return;
        }

        if ($recording->recording_media) {
            return;
        }

        IngestMeetingRecordingMedia::dispatch($recording->id);
    }
}
