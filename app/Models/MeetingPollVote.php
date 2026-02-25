<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingPollVote extends Model
{
    protected $fillable = [
        'poll_id',
        'participant_id',
        'option_index',
    ];

    protected $casts = [
        'option_index' => 'integer',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(MeetingPoll::class, 'poll_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'participant_id');
    }
}
