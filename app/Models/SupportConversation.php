<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SupportConversation extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_BOT_ACTIVE = 'bot_active';

    public const STATUS_WAITING_HUMAN = 'waiting_human';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'requester_user_id',
        'guest_name',
        'guest_email',
        'guest_token',
        'status',
        'priority',
        'channel',
        'subject',
        'source_url',
        'assigned_to',
        'ai_enabled',
        'survey_opt_out',
        'survey_opt_out_at',
        'ai_handoff_required',
        'ai_handoff_reason',
        'last_message_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'ended_at',
        'ended_by_type',
        'ended_by_user_id',
        'ended_by_name',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'survey_opt_out' => 'boolean',
            'ai_handoff_required' => 'boolean',
            'metadata' => 'array',
            'survey_opt_out_at' => 'datetime',
            'last_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportConversation $conversation): void {
            if (empty($conversation->public_id)) {
                $conversation->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<User, SupportConversation>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return BelongsTo<User, SupportConversation>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, SupportConversation>
     */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    /**
     * @return HasMany<SupportMessage>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id')->orderBy('created_at');
    }

    /**
     * @return HasOne<SupportMessage>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class, 'conversation_id')->latestOfMany('created_at');
    }

    /**
     * @return HasMany<SupportGuestSession>
     */
    public function guestSessions(): HasMany
    {
        return $this->hasMany(SupportGuestSession::class, 'conversation_id');
    }

    /**
     * @return HasMany<SupportSurveyInvite>
     */
    public function surveyInvites(): HasMany
    {
        return $this->hasMany(SupportSurveyInvite::class, 'conversation_id');
    }

    /**
     * @return HasMany<SupportSurveyResponse>
     */
    public function surveyResponses(): HasMany
    {
        return $this->hasMany(SupportSurveyResponse::class, 'conversation_id');
    }

    public function isClosedLike(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }
}
