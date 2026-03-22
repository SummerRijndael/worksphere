<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRoutingQueueEntry extends Model
{
    use HasFactory;

    public const STATE_PENDING = 'pending';

    public const STATE_ROUTING = 'routing';

    public const STATE_ASSIGNED = 'assigned';

    public const STATE_FAILED = 'failed';

    public const STATE_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'support_skill_id',
        'assigned_to',
        'state',
        'enqueue_reason',
        'priority',
        'attempts',
        'max_attempts',
        'last_error',
        'meta',
        'next_attempt_at',
        'last_routed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'meta' => 'array',
            'next_attempt_at' => 'datetime',
            'last_routed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportConversation, SupportRoutingQueueEntry>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<SupportSkill, SupportRoutingQueueEntry>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(SupportSkill::class, 'support_skill_id');
    }

    /**
     * @return BelongsTo<User, SupportRoutingQueueEntry>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}

