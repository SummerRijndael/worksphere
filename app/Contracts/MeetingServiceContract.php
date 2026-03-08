<?php

namespace App\Contracts;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;

interface MeetingServiceContract
{
    public function createMeeting(User $user, array $data): Meeting;

    public function updateMeeting(Meeting $meeting, array $data): Meeting;

    public function deleteMeeting(Meeting $meeting): void;

    public function joinMeeting(Meeting $meeting, ?User $user, ?string $guestName, ?string $guestEmail, ?string $providedPassword, ?string $participantSessionId): array;

    public function admitParticipant(Meeting $meeting, MeetingParticipant $participant): MeetingParticipant;

    public function rejectParticipant(Meeting $meeting, MeetingParticipant $participant): void;

    public function promoteParticipant(Meeting $meeting, MeetingParticipant $participant): MeetingParticipant;

    public function demoteParticipant(Meeting $meeting, MeetingParticipant $participant): MeetingParticipant;

    public function authenticateBroadcasting(Meeting $meeting, ?User $user, string $channelName, string $socketId, ?string $participantSessionId);

    public function generateTurnCredentials(): array;

    public function startBreakout(Meeting $meeting, array $rooms, ?int $durationMinutes): void;

    public function endBreakout(Meeting $meeting): void;

    public function joinBreakoutRoom(Meeting $meeting, MeetingParticipant $participant, ?string $roomId): void;

    public function requestBreakoutHelp(Meeting $meeting, string $roomId): void;

    public function moveParticipantToBreakout(Meeting $meeting, string $participantPublicId, ?string $targetRoomId): void;

    public function updateBreakoutTimer(Meeting $meeting, int $additionalMinutes): void;

    public function notifyBreakoutActivity(Meeting $meeting, string $message, ?string $targetRoomId = null): void;
}
