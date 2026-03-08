<?php

namespace App\Services;

use App\Contracts\MeetingServiceContract;
use App\Events\Meetings\MeetingParticipantAdmitted;
use App\Events\Meetings\MeetingParticipantJoined;
use App\Events\Meetings\MeetingSignal;
use App\Mail\EventInvitation;
use App\Models\BreakoutSession;
use App\Models\Event;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\Chat\PresenceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Pusher\Pusher;

class MeetingService implements MeetingServiceContract
{
    public function createMeeting(User $user, array $data): Meeting
    {
        $participants = $data['participants'] ?? [];
        $internalParticipants = collect($participants)->filter(fn ($p) => ($p['type'] ?? 'user') === 'user');
        $externalParticipants = collect($participants)->filter(fn ($p) => ($p['type'] ?? 'user') === 'email');

        $guestAccessEnabled = $data['settings']['guest_access'] ?? false;

        // Smart external guest validation
        if ($externalParticipants->isNotEmpty() && ! $guestAccessEnabled) {
            throw ValidationException::withMessages([
                'participants' => 'External guest emails cannot be added when "Allow external guests" is disabled.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $internalParticipants, $externalParticipants) {
            // Enforce per-user meeting cap. Delete existing meetings to free slots.
            $maxMeetingsPerUser = (int) config('worksphere.meetings.limits.max_meetings_per_user', 15);
            if ($maxMeetingsPerUser > 0) {
                User::query()->whereKey($user->id)->lockForUpdate()->first();

                $createdMeetingsCount = Meeting::where('user_id', $user->id)->count();
                if ($createdMeetingsCount >= $maxMeetingsPerUser) {
                    throw ValidationException::withMessages([
                        'meetings' => "You've reached the {$maxMeetingsPerUser}-meeting limit. Delete an existing meeting to create a new one.",
                    ]);
                }
            }

            $meeting = Meeting::create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'] ?? null,
                'status' => 'scheduled',
                'settings' => $data['settings'] ?? [],
                'password' => $data['password'] ?? null,
                'app_id' => 'worksphere',
            ]);

            // Host is automatically a participant
            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'user_id' => $user->id,
                'role' => 'host',
                'status' => 'admitted',
            ]);

            // Save to calendar if requested
            if ($data['save_to_calendar'] ?? false) {
                $externalEmails = $externalParticipants->pluck('email')->filter()->values()->toArray();
                $internalIds = $internalParticipants->pluck('id')->filter()->values()->toArray();

                $startTime = \Illuminate\Support\Carbon::parse($meeting->start_time);
                $endTime = $meeting->end_time ? \Illuminate\Support\Carbon::parse($meeting->end_time) : $startTime->copy()->addHour();

                $event = Event::create([
                    'user_id' => $user->id,
                    'title' => $meeting->title,
                    'description' => $meeting->description,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'is_all_day' => false,
                    'reminder_minutes_before' => $data['reminder_minutes_before'] ?? null,
                    'external_attendees' => $externalEmails,
                    'meeting_id' => $meeting->id,
                ]);

                // Attach internal attendees (resolve public_ids to internal ids if needed)
                if (! empty($internalIds)) {
                    $userIds = User::whereIn('public_id', $internalIds)->pluck('id');
                    if ($userIds->isEmpty()) {
                        // Try by integer id directly
                        $userIds = collect($internalIds);
                    }
                    $event->attendees()->attach($userIds);
                }

                // Send invitations if requested
                if ($data['send_invite'] ?? false) {
                    $attendeeUsers = $event->attendees;
                    foreach ($attendeeUsers as $attendee) {
                        if ($attendee->id !== $user->id) {
                            Mail::to($attendee)->queue(new EventInvitation($event));
                        }
                    }
                    foreach ($externalEmails as $email) {
                        Mail::to($email)->queue(new EventInvitation($event));
                    }
                }
            }

            return $meeting;
        });
    }

    public function updateMeeting(Meeting $meeting, array $data): Meeting
    {
        $meeting->update($data);

        return $meeting;
    }

    public function deleteMeeting(Meeting $meeting): void
    {
        $meeting->delete();
    }

    public function joinMeeting(Meeting $meeting, ?User $user, ?string $guestName, ?string $guestEmail, ?string $providedPassword, ?string $participantSessionId, bool $isCompanion = false): array
    {
        $start = microtime(true);
        Log::channel('videocall')->info('[504_DEBUG] Join start', ['meeting' => $meeting->public_id]);

        // 1. Basic Meeting Status Checks
        $isHost = $user && $meeting->user_id === $user->id;

        if ($meeting->status === 'ended' && ! $isHost) {
            abort(403, 'This meeting has already ended.');
        }

        // Auto-activate meeting if host joins
        if ($isHost && $meeting->status !== 'active') {
            $meeting->update([
                'status' => 'active',
                'actual_start_time' => now(),
            ]);
            broadcast(new \App\Events\Meetings\MeetingStatusUpdated($meeting));
        }
        Log::channel('videocall')->info('[504_DEBUG] Step 1: Status check done', ['time' => microtime(true) - $start]);

        $isGuest = ! $user;

        if ($isGuest && ! ($meeting->settings['guest_access'] ?? false)) {
            abort(403, 'Guest access disabled for this meeting.');
        }

        // 2. Password Check
        if ($meeting->password && ($meeting->user_id !== ($user ? $user->id : null))) {
            if ($providedPassword !== $meeting->password) {
                // Return structured error via exception or handled response
                throw new \Exception('Invalid meeting password. REQUIRES_PASSWORD');
            }
        }
        Log::channel('videocall')->info('[504_DEBUG] Step 2: Password check done', ['time' => microtime(true) - $start]);

        // 3. ACL & Smart Waiting Room logic
        $isInvitedOnly = $meeting->settings['invited_only'] ?? false;
        $isWhitelistMatch = false;

        $meeting->load('event');
        Log::channel('videocall')->info('[504_DEBUG] Step 3a: Event loaded', ['time' => microtime(true) - $start]);

        // Check if user/guest is on the Calendar Event whitelist
        if ($meeting->event) {
            if ($user && $meeting->event->attendees()->where('user_id', $user->id)->exists()) {
                $isWhitelistMatch = true;
            } elseif ($guestEmail && in_array($guestEmail, $meeting->event->external_attendees ?? [])) {
                $isWhitelistMatch = true;
            }
        }
        Log::channel('videocall')->info('[504_DEBUG] Step 3b: Whitelist check done', ['time' => microtime(true) - $start]);

        if ($isInvitedOnly && ! $isWhitelistMatch && $meeting->user_id !== ($user ? $user->id : null)) {
            abort(403, 'This meeting is restricted to invited participants only.');
        }
        Log::channel('videocall')->info('[504_DEBUG] Step 3c: Invited only check done', ['time' => microtime(true) - $start]);

        // 4. Meeting Lock check
        $isLocked = Cache::has("meeting:lock:{$meeting->public_id}");
        if ($isLocked && ($meeting->user_id !== ($user ? $user->id : null))) {
            $isAlreadyIn = MeetingParticipant::where('meeting_id', $meeting->id)
                ->where('status', 'admitted')
                ->where(function ($q) use ($user, $participantSessionId) {
                    if ($user) {
                        $q->where('user_id', $user->id);
                    } else {
                        $q->where('public_id', $participantSessionId);
                    }
                })->exists();

            if (! $isAlreadyIn) {
                abort(403, 'This meeting is locked by the host.');
            }
        }
        Log::channel('videocall')->info('[504_DEBUG] Step 4: Lock check done', ['time' => microtime(true) - $start]);

        // 4.25 Optional gate: participants may only join after a host/co-host is already in-room.
        // Instead of hard-rejecting, we place them in waiting state with a specific reason
        // so the frontend can show "waiting for host/co-host to join".
        $requiresModeratorPresent = (bool) ($meeting->settings['require_host_or_cohost_present'] ?? false);
        $mustWaitForModerator = false;
        if ($requiresModeratorPresent && ! $this->isJoiningAsModerator($meeting, $user, $participantSessionId)) {
            if (! $this->hasActiveHostOrCohostInRoom($meeting)) {
                $mustWaitForModerator = true;
            }
        }

        // 4.5 Capacity Check
        $isPro = config('worksphere.meetings.pro_mode', false);
        $maxParticipants = $isPro
            ? config('worksphere.meetings.limits.pro_max_participants', 50)
            : config('worksphere.meetings.limits.free_max_participants', 25);

        $isAlreadyParticipant = false;
        if (! $isCompanion) {
            if ($user && MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->whereIn('status', ['admitted', 'waiting'])->exists()) {
                $isAlreadyParticipant = true;
            } elseif ($participantSessionId && MeetingParticipant::where('meeting_id', $meeting->id)->where('public_id', $participantSessionId)->whereIn('status', ['admitted', 'waiting'])->exists()) {
                $isAlreadyParticipant = true;
            }
        }

        if (! $isAlreadyParticipant && ! $isHost) {
            $currentCount = MeetingParticipant::where('meeting_id', $meeting->id)
                ->whereIn('status', ['admitted', 'waiting'])
                ->count();

            if ($currentCount >= $maxParticipants) {
                abort(403, "This meeting has reached its maximum capacity of {$maxParticipants} participants.");
            }
        }
        Log::channel('videocall')->info('[504_DEBUG] Step 4.5: Capacity check done', ['time' => microtime(true) - $start]);

        // 5. Determine participant status
        // If whitelisted or host, bypass wait room. Otherwise check lobby_enabled
        $lobbyEnabled = $meeting->settings['lobby_enabled'] ?? true;
        $status = 'waiting';

        if (! $mustWaitForModerator && ($meeting->user_id === ($user ? $user->id : null) || $isWhitelistMatch || ! $lobbyEnabled)) {
            $status = 'admitted';
        }

        // Re-joining? Retain 'admitted' status if previously admitted
        // If it's a companion device, we always want a fresh record.
        if (! $isCompanion) {
            if ($user) {
                $existing = MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->first();
                if ($existing && $existing->status === 'admitted' && ! $mustWaitForModerator) {
                    $status = 'admitted';
                }
            } elseif ($participantSessionId) {
                $existing = MeetingParticipant::where('meeting_id', $meeting->id)->where('public_id', $participantSessionId)->first();
                if ($existing && $existing->status === 'admitted' && ! $mustWaitForModerator) {
                    $status = 'admitted';
                }
            }
        }

        // 6. Create or Get Participant
        if ($isGuest) {
            // Recover guest session if exists, otherwise create new
            $participant = null;
            if ($participantSessionId && ! $isCompanion) {
                $participant = MeetingParticipant::where('meeting_id', $meeting->id)
                    ->where('public_id', $participantSessionId)
                    ->first();
            }

            if (! $participant) {
                $name = $guestName ?? 'Guest';
                if ($isCompanion) {
                    $name .= ' (Presentation)';
                }

                $participant = MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'public_id' => (string) Str::ulid(),
                    'role' => 'participant',
                    'status' => $status,
                    'metadata' => [
                        'guest_name' => $name,
                        'guest_email' => $guestEmail,
                        'is_companion' => $isCompanion,
                        'fingerprint' => [
                            'ip' => request()->ip(),
                            'ua' => request()->userAgent(),
                        ],
                    ],
                ]);
            }
        } else {
            $participant = null;
            if (! $isCompanion) {
                $participant = MeetingParticipant::where([
                    'meeting_id' => $meeting->id,
                    'user_id' => $user->id,
                ])->first();
            }

            if (! $participant) {
                $participant = MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $user->id,
                    'role' => $meeting->user_id === $user->id ? 'host' : 'participant',
                    'status' => $status,
                    'metadata' => [
                        'is_companion' => $isCompanion,
                        'display_name_override' => $isCompanion ? $user->name.' (Presentation)' : null,
                        'fingerprint' => [
                            'ip' => request()->ip(),
                            'ua' => request()->userAgent(),
                        ],
                    ],
                ]);
            }
        }
        // Keep status/metadata in sync even for existing participant records.
        // This is required for gate transitions (e.g. host absent -> waiting with reason).
        $metadata = $participant->metadata ?? [];
        $metadataChanged = false;
        if ($mustWaitForModerator) {
            if (($metadata['waiting_reason'] ?? null) !== 'awaiting_moderator') {
                $metadata['waiting_reason'] = 'awaiting_moderator';
                $metadataChanged = true;
            }
        } elseif (($metadata['waiting_reason'] ?? null) === 'awaiting_moderator') {
            unset($metadata['waiting_reason']);
            $metadataChanged = true;
        }

        if ($participant->status !== $status || $metadataChanged) {
            $participant->status = $status;
            if ($metadataChanged) {
                $participant->metadata = $metadata;
            }
            $participant->save();
            $participant->refresh();
        }
        Log::channel('videocall')->info('[504_DEBUG] Step 6: Participant DB done', ['time' => microtime(true) - $start]);

        // 7. Broadcasts
        broadcast(new MeetingParticipantJoined($meeting, $participant));
        Log::channel('videocall')->info('[504_DEBUG] Step 7a: Broadcast Join done', ['time' => microtime(true) - $start]);

        if ($participant->status === 'waiting') {
            broadcast(new MeetingSignal(
                $meeting,
                $participant->public_id,
                'participant-waiting',
                [
                    'participant_id' => $participant->public_id,
                    'display_name' => $participant->metadata['guest_name'] ?? ($participant->user?->name ?? 'Someone'),
                ]
            ));
            Log::channel('videocall')->info('[504_DEBUG] Step 7b: Broadcast Waiting done', ['time' => microtime(true) - $start]);
        }

        Log::channel('videocall')->info('[PARTICIPANT] Join attempt', [
            'meeting' => $meeting->public_id,
            'is_guest' => $isGuest,
            'status' => $status,
            'participant' => $participant->public_id,
        ]);

        return [
            'meeting' => $meeting->load(['host', 'participants.user', 'activeBreakoutSession']),
            'participant' => $participant,
        ];
    }

    private function isJoiningAsModerator(Meeting $meeting, ?User $user, ?string $participantSessionId): bool
    {
        // Meeting owner is always the host.
        if ($user && $meeting->user_id === $user->id) {
            return true;
        }

        $query = MeetingParticipant::where('meeting_id', $meeting->id)
            ->whereIn('role', ['host', 'co-host']);

        if ($user) {
            $query->where('user_id', $user->id);
        } elseif (! empty($participantSessionId)) {
            $query->whereRaw('LOWER(public_id) = ?', [strtolower($participantSessionId)]);
        } else {
            return false;
        }

        return $query->exists();
    }

    private function hasActiveHostOrCohostInRoom(Meeting $meeting): bool
    {
        try {
            $activeParticipantIds = app(PresenceService::class)->getActiveMeetingParticipantIds($meeting->public_id);

            if (! is_array($activeParticipantIds) || empty($activeParticipantIds)) {
                return false;
            }

            $normalizedIds = collect($activeParticipantIds)
                ->map(fn ($id) => strtolower((string) $id))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (empty($normalizedIds)) {
                return false;
            }

            return MeetingParticipant::where('meeting_id', $meeting->id)
                ->whereIn('role', ['host', 'co-host'])
                ->whereIn(DB::raw('LOWER(public_id)'), $normalizedIds)
                ->exists();
        } catch (\Throwable $e) {
            // Fail-open if live presence cannot be queried (e.g., Redis unavailable).
            Log::warning('[MEETING_GUARD] Failed to validate active host/co-host presence; allowing join', [
                'meeting' => $meeting->public_id,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    public function admitParticipant(Meeting $meeting, MeetingParticipant $participant): MeetingParticipant
    {
        $participant->update(['status' => 'admitted']);

        Log::channel('videocall')->info('[PARTICIPANT] Admitted', [
            'meeting' => $meeting->public_id,
            'participant' => $participant->public_id,
        ]);

        broadcast(new MeetingParticipantAdmitted($meeting, $participant));
        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'participant-admitted',
            ['admitted_participant_id' => $participant->public_id],
            $participant->public_id
        ));

        return $participant;
    }

    public function rejectParticipant(Meeting $meeting, MeetingParticipant $participant): void
    {
        $participant->update(['status' => 'rejected']);

        Log::channel('videocall')->info('[PARTICIPANT] Rejected', [
            'meeting' => $meeting->public_id,
            'participant' => $participant->public_id,
        ]);

        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'participant-rejected',
            ['targetId' => $participant->public_id],
            $participant->public_id
        ));
    }

    public function promoteParticipant(Meeting $meeting, MeetingParticipant $participant): MeetingParticipant
    {
        $participant->update(['role' => 'co-host']);

        $hostParticipant = $meeting->participants()->where('user_id', $meeting->user_id)->first();
        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'role-changed',
            ['targetId' => $participant->public_id, 'role' => 'co-host']
        ));

        return $participant;
    }

    public function demoteParticipant(Meeting $meeting, MeetingParticipant $participant): MeetingParticipant
    {
        $participant->update(['role' => 'participant']);

        $hostParticipant = $meeting->participants()->where('user_id', $meeting->user_id)->first();
        broadcast(new MeetingSignal(
            $meeting,
            $hostParticipant ? $hostParticipant->public_id : 'system',
            'role-changed',
            ['targetId' => $participant->public_id, 'role' => 'participant']
        ));

        return $participant;
    }

    public function authenticateBroadcasting(Meeting $meeting, ?User $user, string $channelName, string $socketId, ?string $participantSessionId)
    {
        $isPresence = str_starts_with($channelName, 'presence-');

        // 1. If user is the host (Authenticated Owner)
        if ($user && $meeting->user_id === $user->id) {
            $hostParticipant = MeetingParticipant::where('meeting_id', $meeting->id)
                ->where('user_id', $user->id)
                ->first();

            // Safety: Ensure host participant record exists
            if (! $hostParticipant) {
                $hostParticipant = MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $user->id,
                    'role' => 'host',
                    'status' => 'admitted',
                ]);
            }

            $participantId = $hostParticipant->public_id;

            // SECURITY: If a participantSessionId was provided, it MUST match the host's record
            // This prevents a host from accidentally (or maliciously) using a guest participant ID
            if ($participantSessionId && strtolower($participantSessionId) !== strtolower($participantId)) {
                Log::warning('[SECURITY] Host attempted to authenticate with mismatched participant ID', [
                    'user' => $user->id,
                    'expected' => $participantId,
                    'provided' => $participantSessionId,
                ]);
                abort(403, 'Mismatched participant session.');
            }

            $userData = [
                'public_id' => $participantId,
                'name' => $user->name,
                'avatar' => $user->avatar_url,
                'role' => 'host',
                'status' => 'admitted',
            ];

            return $this->generatePusherAuth($channelName, $socketId, $participantId, $userData, $isPresence);
        }

        // 2. Participant check (Guest or other Member)
        if (! $participantSessionId) {
            abort(403, 'Unauthorized. No participant session found.');
        }

        $participantQuery = MeetingParticipant::where('meeting_id', $meeting->id)
            ->whereRaw('LOWER(public_id) = ?', [strtolower($participantSessionId)]);

        if ($user) {
            // For logged in users, ensure the participant record belongs to them
            $participantQuery->where('user_id', $user->id);
        } else {
            // For guests, verify against the session to prevent hijacking via URL
            $sessionPid = session('meeting_participant_id') ?: session('participant_id');
            if (! $sessionPid || strtolower($sessionPid) !== strtolower($participantSessionId)) {
                Log::warning('[SECURITY] Guest attempted to authenticate with mismatched session ID', [
                    'session_pid' => $sessionPid,
                    'provided_pid' => $participantSessionId,
                ]);
                abort(403, 'Mismatched meeting session. Please refresh or re-join.');
            }
        }

        $participant = $participantQuery->first();

        if (! $participant) {
            abort(403, 'Unauthorized. Participant session invalid for this meeting.');
        }

        // 3. Optional: Fingerprint Check (Defense in Depth)
        // Verify that the broadcasting request comes from the same device/IP that joined
        $storedFingerprint = $participant->metadata['fingerprint'] ?? null;
        if ($storedFingerprint) {
            $currentIp = request()->ip();
            $currentUa = request()->userAgent();

            // We do a "soft" check on IP (might change on mobile) but strict on UA
            if ($storedFingerprint['ua'] !== $currentUa) {
                Log::warning('[SECURITY] Session Hijack Attempt: UA Mismatch', [
                    'participant' => $participant->public_id,
                    'expected_ua' => $storedFingerprint['ua'],
                    'current_ua' => $currentUa,
                ]);
                // For now, we only log UA mismatch to avoid false positives with browser updates,
                // but we could abort(403) here for maximum security.
            }

            if ($storedFingerprint['ip'] !== $currentIp) {
                Log::info('[SECURITY] Participant IP changed', [
                    'participant' => $participant->public_id,
                    'from' => $storedFingerprint['ip'],
                    'to' => $currentIp,
                ]);
            }
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

    private function generatePusherAuth($channelName, $socketId, $userId, $userData, $isPresence)
    {
        $connection = config('broadcasting.default');
        $config = config("broadcasting.connections.{$connection}");

        $pusher = new Pusher(
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

    public function generateTurnCredentials(): array
    {
        $iceServers = [
            ['urls' => 'stun:stun.cloudflare.com:3478'],
        ];

        $turnKeyId = config('services.cloudflare.turn_key_id');
        $turnApiToken = config('services.cloudflare.turn_api_token');

        if ($turnKeyId && $turnApiToken) {
            try {
                $response = Http::withToken($turnApiToken)
                    ->post("https://rtc.live.cloudflare.com/v1/turn/keys/{$turnKeyId}/credentials/generate-ice-servers", [
                        'ttl' => 3600,
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if (! empty($data['iceServers'])) {
                        $iceServers = $data['iceServers'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch TURN credentials', ['error' => $e->getMessage()]);
            }
        }

        return ['ice_servers' => $iceServers];
    }

    public function startBreakout(Meeting $meeting, array $rooms, ?int $durationMinutes): void
    {
        DB::transaction(function () use ($meeting, $rooms, $durationMinutes) {
            // Cleanup existing active sessions to prevent state bloat/desync
            BreakoutSession::where('meeting_id', $meeting->id)
                ->where('status', 'active')
                ->update(['status' => 'ended', 'ended_at' => now()]);

            // Create new session record
            $session = BreakoutSession::create([
                'meeting_id' => $meeting->id,
                'status' => 'active',
                'rooms_config' => $rooms,
                'duration_minutes' => $durationMinutes,
                'started_at' => now(),
            ]);

            // Update participant assignments
            foreach ($rooms as $room) {
                $roomId = (string) $room['id'];
                foreach ($room['participants'] as $pData) {
                    MeetingParticipant::where('meeting_id', $meeting->id)
                        ->where('public_id', $pData['public_id'])
                        ->update(['assigned_room_id' => $roomId]);
                }
            }

            // Broadcast breakout-started signal to everyone
            broadcast(new MeetingSignal(
                $meeting,
                'system',
                'breakout-started',
                [
                    'public_id' => $session->public_id,
                    'rooms' => $rooms,
                    'duration' => $durationMinutes,
                    'started_at' => $session->started_at->toIso8601String(),
                ]
            ));
        });
    }

    public function endBreakout(Meeting $meeting): void
    {
        DB::transaction(function () use ($meeting) {
            $session = $meeting->activeBreakoutSession;
            if ($session) {
                $session->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                ]);
            }

            // Clear assignments
            $meeting->participants()->update([
                'assigned_room_id' => null,
                'current_room_id' => null,
            ]);

            broadcast(new MeetingSignal(
                $meeting,
                'system',
                'breakout-ended',
                []
            ));
        });
    }

    public function requestBreakoutHelp(Meeting $meeting, string $roomId): void
    {
        $roomName = 'Room';
        $session = $meeting->activeBreakoutSession;
        if ($session) {
            $room = collect($session->rooms_config)->firstWhere('id', $roomId);
            if ($room) {
                $roomName = $room['name'];
            }
        }

        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'breakout-help-request',
            [
                'room_id' => $roomId,
                'room_name' => $roomName,
            ]
        ));
    }

    public function moveParticipantToBreakout(Meeting $meeting, string $participantPublicId, ?string $targetRoomId): void
    {
        Log::info('Move participant request', [
            'meeting' => $meeting->public_id,
            'participant' => $participantPublicId,
            'target_room' => $targetRoomId,
        ]);

        $participant = $meeting->participants()
            ->whereRaw('LOWER(public_id) = ?', [strtolower($participantPublicId)])
            ->first();
        if ($participant) {
            $participant->update(['assigned_room_id' => $targetRoomId]);
            Log::info('Participant assigned_room_id updated', [
                'participant' => $participant->public_id,
                'new_assigned_room_id' => $targetRoomId,
            ]);
        }

        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'breakout-move',
            [
                'target_id' => $participantPublicId,
                'target_room_id' => $targetRoomId, // null means Main Room
            ]
        ));
    }

    public function updateBreakoutTimer(Meeting $meeting, int $additionalMinutes): void
    {
        DB::transaction(function () use ($meeting, $additionalMinutes) {
            $session = BreakoutSession::query()
                ->where('meeting_id', $meeting->id)
                ->where('status', 'active')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                abort(422, 'No active breakout session was found.');
            }

            if ($session->duration_minutes === null) {
                abort(422, 'This breakout session has no timer configured.');
            }

            $newDuration = (int) $session->duration_minutes + $additionalMinutes;
            if ($newDuration < 1) {
                abort(422, 'Timer must remain at least 1 minute.');
            }

            $session->update([
                'duration_minutes' => $newDuration,
            ]);

            $elapsedSeconds = max(0, (int) $session->started_at?->diffInSeconds(now()));
            $remainingSeconds = max(0, ($newDuration * 60) - $elapsedSeconds);

            broadcast(new MeetingSignal(
                $meeting,
                'system',
                'breakout-timer-updated',
                [
                    'additional_minutes' => $additionalMinutes,
                    'duration_minutes' => $newDuration,
                    'remaining_seconds' => $remainingSeconds,
                    'updated_at' => now()->toIso8601String(),
                ]
            ));
        });
    }

    public function joinBreakoutRoom(Meeting $meeting, MeetingParticipant $participant, ?string $roomId): void
    {
        if (in_array(strtolower((string) $roomId), ['main', 'null', ''], true)) {
            $roomId = null;
        }

        // Force refresh from database to avoid race conditions with recent moveParticipantToBreakout updates
        $participant->refresh();

        // AUTHORIZATION: Only allow if host or if assigned to this room
        $isHost = $meeting->user_id === $participant->user_id;
        $isAssigned = (string) $participant->assigned_room_id === (string) $roomId;

        Log::info('Join breakout room attempt', [
            'meeting' => $meeting->public_id,
            'participant' => $participant->public_id,
            'room_id' => $roomId,
            'assigned_room_id' => $participant->assigned_room_id,
            'is_host' => $isHost,
            'is_assigned' => $isAssigned,
        ]);

        if (! $isHost && ! $isAssigned) {
            abort(403, 'You are not assigned to this breakout room.');
        }

        // Update current room in DB
        $participant->update(['current_room_id' => $roomId]);

        // Broadcast move signal so everyone's local store updates participant position
        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'breakout-move',
            [
                'target_id' => $participant->public_id,
                'target_room_id' => $roomId,
            ]
        ));

        // Broadcast join activity message
        $this->notifyBreakoutActivity(
            $meeting,
            ($participant->metadata['guest_name'] ?? ($participant->user?->name ?? 'Someone')).' joined the room',
            $roomId
        );
    }

    public function notifyBreakoutActivity(Meeting $meeting, string $message, ?string $targetRoomId = null): void
    {
        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'breakout-activity',
            [
                'message' => $message,
                'target_room_id' => $targetRoomId,
            ]
        ));
    }
}
