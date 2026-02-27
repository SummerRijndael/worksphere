<?php

namespace App\Services;

use App\Contracts\MeetingServiceContract;
use App\Events\Meetings\MeetingParticipantAdmitted;
use App\Events\Meetings\MeetingParticipantJoined;
use App\Events\Meetings\MeetingSignal;
use App\Models\BreakoutSession;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pusher\Pusher;

class MeetingService implements MeetingServiceContract
{
    public function createMeeting(User $user, array $data): Meeting
    {
        return DB::transaction(function () use ($user, $data) {
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

    public function joinMeeting(Meeting $meeting, ?User $user, ?string $guestName, ?string $guestEmail, ?string $providedPassword, ?string $participantSessionId): array
    {
        // 1. Basic Meeting Status Checks
        if ($meeting->status === 'ended') {
            abort(403, 'This meeting has already ended.');
        }

        $isGuest = !$user;

        if ($isGuest && !($meeting->settings['guest_access'] ?? false)) {
            abort(403, 'Guest access disabled for this meeting.');
        }

        // 2. Password Check
        if ($meeting->password && ($meeting->user_id !== ($user ? $user->id : null))) {
            if ($providedPassword !== $meeting->password) {
                // Return structured error via exception or handled response
                throw new \Exception('Invalid meeting password. REQUIRES_PASSWORD');
            }
        }

        // 3. ACL & Smart Waiting Room logic
        $isInvitedOnly = $meeting->settings['invited_only'] ?? false;
        $isWhitelistMatch = false;
        
        $meeting->load('event');

        // Check if user/guest is on the Calendar Event whitelist
        if ($meeting->event) {
            if ($user && $meeting->event->attendees()->where('user_id', $user->id)->exists()) {
                $isWhitelistMatch = true;
            } elseif ($guestEmail && in_array($guestEmail, $meeting->event->external_attendees ?? [])) {
                $isWhitelistMatch = true;
            }
        }

        if ($isInvitedOnly && !$isWhitelistMatch && $meeting->user_id !== ($user ? $user->id : null)) {
            abort(403, 'This meeting is restricted to invited participants only.');
        }

        // 4. Meeting Lock check
        if ($meeting->is_locked && ($meeting->user_id !== ($user ? $user->id : null))) {
            $isAlreadyIn = MeetingParticipant::where('meeting_id', $meeting->id)
                ->where('status', 'admitted')
                ->where(function ($q) use ($user, $participantSessionId) {
                    if ($user) $q->where('user_id', $user->id);
                    else $q->where('public_id', $participantSessionId);
                })->exists();

            if (!$isAlreadyIn) {
                abort(403, 'This meeting is locked by the host.');
            }
        }

        // 5. Determine participant status
        // If whitelisted or host, bypass wait room. Otherwise check lobby_enabled
        $lobbyEnabled = $meeting->settings['lobby_enabled'] ?? true;
        $status = 'waiting';
        
        if ($meeting->user_id === ($user ? $user->id : null) || $isWhitelistMatch || !$lobbyEnabled) {
            $status = 'admitted';
        }

        // Re-joining? Retain 'admitted' status if previously admitted
        if ($user) {
            $existing = MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->first();
            if ($existing && $existing->status === 'admitted') {
                $status = 'admitted';
            }
        } elseif ($participantSessionId) {
             $existing = MeetingParticipant::where('meeting_id', $meeting->id)->where('public_id', $participantSessionId)->first();
             if ($existing && $existing->status === 'admitted') {
                 $status = 'admitted';
             }
        }

        // 6. Create or Get Participant
        if ($isGuest) {
            // Recover guest session if exists, otherwise create new
            $participant = null;
            if ($participantSessionId) {
                $participant = MeetingParticipant::where('meeting_id', $meeting->id)
                    ->where('public_id', $participantSessionId)
                    ->first();
            }

            if (!$participant) {
                $participant = MeetingParticipant::create([
                    'meeting_id' => $meeting->id,
                    'public_id' => (string) Str::ulid(),
                    'role' => 'participant',
                    'status' => $status,
                    'metadata' => [
                        'guest_name' => $guestName ?? 'Guest',
                        'guest_email' => $guestEmail,
                    ],
                ]);
            } else {
                // Optional: potentially promote back to waiting if they hit weird state, 
                // but generally just return the participant.
            }
        } else {
            $participant = MeetingParticipant::firstOrCreate(
                [
                    'meeting_id' => $meeting->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $meeting->user_id === $user->id ? 'host' : 'participant',
                    'status' => $status,
                ]
            );
        }

        // 7. Broadcasts
        broadcast(new MeetingParticipantJoined($meeting, $participant));

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
        }

        return [
            'meeting' => $meeting->load(['host', 'participants.user', 'activeBreakoutSession']), 
            'participant' => $participant
        ];
    }

    public function admitParticipant(Meeting $meeting, MeetingParticipant $participant): MeetingParticipant
    {
        $participant->update(['status' => 'admitted']);

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

        // 2. Participant check
        $participantQuery = MeetingParticipant::where('meeting_id', $meeting->id);

        if ($user) {
            $participantQuery->where(function($q) use ($user, $participantSessionId) {
                $q->where('user_id', $user->id);
                if ($participantSessionId) {
                    $q->orWhere('public_id', $participantSessionId);
                }
            });
        } else {
            if (!$participantSessionId) {
                abort(403, 'Unauthorized. No participant ID.');
            }
            $participantQuery->where('public_id', $participantSessionId);
        }

        $participant = $participantQuery->first();

        if (!$participant) {
            abort(403, 'Unauthorized. Participant not found in this meeting.');
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

    public function startBreakout(Meeting $meeting, array $rooms, int $durationMinutes): void
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
                    'ended_at' => now()
                ]);
            }

            // Clear assignments
            $meeting->participants()->update([
                'assigned_room_id' => null,
                'current_room_id' => null
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
            if ($room) $roomName = $room['name'];
        }

        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'breakout-help-request',
            [
                'room_id' => $roomId,
                'room_name' => $roomName
            ]
        ));
    }

    public function moveParticipantToBreakout(Meeting $meeting, string $participantPublicId, ?string $targetRoomId): void
    {
        Log::info('Move participant request', [
            'meeting' => $meeting->public_id,
            'participant' => $participantPublicId,
            'target_room' => $targetRoomId
        ]);

        $participant = $meeting->participants()
            ->whereRaw('LOWER(public_id) = ?', [strtolower($participantPublicId)])
            ->first();
        if ($participant) {
            $participant->update(['assigned_room_id' => $targetRoomId]);
            Log::info('Participant assigned_room_id updated', [
                'participant' => $participant->public_id,
                'new_assigned_room_id' => $targetRoomId
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
        broadcast(new MeetingSignal(
            $meeting,
            'system',
            'breakout-timer-updated',
            [
                'additional_minutes' => $additionalMinutes,
                'updated_at' => now()->toIso8601String(),
            ]
        ));
    }

    public function joinBreakoutRoom(Meeting $meeting, MeetingParticipant $participant, string $roomId): void
    {
        // Force refresh from database to avoid race conditions with recent moveParticipantToBreakout updates
        $participant->refresh();

        // AUTHORIZATION: Only allow if host or if assigned to this room
        $isHost = $meeting->user_id === $participant->user_id;
        $isAssigned = (string)$participant->assigned_room_id === (string)$roomId;

        Log::info('Join breakout room attempt', [
            'meeting' => $meeting->public_id,
            'participant' => $participant->public_id,
            'room_id' => $roomId,
            'assigned_room_id' => $participant->assigned_room_id,
            'is_host' => $isHost,
            'is_assigned' => $isAssigned
        ]);

        if (!$isHost && !$isAssigned) {
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
                'target_room_id' => $roomId
            ]
        ));

        // Broadcast join activity message
        $this->notifyBreakoutActivity(
            $meeting,
            ($participant->metadata['guest_name'] ?? ($participant->user?->name ?? 'Someone')) . " joined the room",
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
