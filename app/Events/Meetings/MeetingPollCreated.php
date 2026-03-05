<?php

namespace App\Events\Meetings;

use App\Models\Meeting;
use App\Models\MeetingPoll;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingPollCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $meetingPublicId;

    public array $poll;

    public function __construct(Meeting $meeting, MeetingPoll $poll)
    {
        $this->meetingPublicId = $meeting->public_id;
        $this->poll = [
            'public_id' => $poll->public_id,
            'question' => $poll->question,
            'options' => $poll->options,
            'is_active' => true,
            'allow_multiple' => (bool) $poll->allow_multiple,
            'allow_change_vote' => (bool) $poll->allow_change_vote,
            'anonymous' => (bool) $poll->anonymous,
            'vote_counts' => array_fill(0, count($poll->options), 0),
            'voter_count' => 0,
            'my_votes' => [],
        ];
    }

    public function broadcastWith(): array
    {
        return ['poll' => $this->poll];
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("meeting.{$this->meetingPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'MeetingPollCreated';
    }
}
