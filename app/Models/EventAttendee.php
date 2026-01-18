<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EventAttendee extends Pivot
{
    protected $table = 'event_attendees';

    protected $fillable = [
        'event_id',
        'user_id',
        'status',
    ];

    public function event(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
