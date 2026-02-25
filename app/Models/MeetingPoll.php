<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MeetingPoll extends Model
{
    protected $fillable = [
        'public_id',
        'meeting_id',
        'created_by',
        'question',
        'options',
        'is_active',
        'ended_at',
        'allow_multiple',
        'allow_change_vote',
        'anonymous',
    ];

    protected $casts = [
        'options' => 'array',
        'is_active' => 'boolean',
        'allow_multiple' => 'boolean',
        'allow_change_vote' => 'boolean',
        'anonymous' => 'boolean',
        'ended_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            $model->public_id = $model->public_id ?? (string) Str::ulid();
        });
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'created_by');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(MeetingPollVote::class, 'poll_id');
    }

    /**
     * Returns aggregated vote counts per option index: [0 => 3, 1 => 1, ...]
     */
    public function getVoteCounts(): array
    {
        $counts = array_fill(0, count($this->options), 0);
        $this->votes()->select('option_index', \DB::raw('count(*) as total'))
            ->groupBy('option_index')
            ->get()
            ->each(fn($row) => $counts[$row->option_index] = $row->total);
        return $counts;
    }
}
