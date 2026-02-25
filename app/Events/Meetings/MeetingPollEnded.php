<?php

namespace App\Events\Meetings;

use App\Models\Meeting;
use App\Models\MeetingPoll;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingPollEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $meetingPublicId;
    public string $pollPublicId;
    public array $finalVoteCounts;

    public function __construct(Meeting $meeting, MeetingPoll $poll)
    {
        $this->meetingPublicId = $meeting->public_id;
        $this->pollPublicId = $poll->public_id;
        $this->finalVoteCounts = $poll->getVoteCounts();
    }

    public function broadcastWith(): array
    {
        return [
            'poll_id'          => $this->pollPublicId,
            'final_vote_counts' => $this->finalVoteCounts,
        ];
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("meeting.{$this->meetingPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'MeetingPollEnded';
    }
}
