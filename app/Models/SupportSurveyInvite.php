<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SupportSurveyInvite extends Model
{
    use HasFactory;

    public const TYPE_CSAT = 'csat';

    public const TYPE_NPS = 'nps';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'conversation_id',
        'requester_user_id',
        'issued_by_user_id',
        'survey_type',
        'status',
        'token_hash',
        'issued_at',
        'expires_at',
        'responded_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportSurveyInvite $invite): void {
            if (empty($invite->public_id)) {
                $invite->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<SupportConversation, SupportSurveyInvite>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, SupportSurveyInvite>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return BelongsTo<User, SupportSurveyInvite>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * @return HasOne<SupportSurveyResponse>
     */
    public function response(): HasOne
    {
        return $this->hasOne(SupportSurveyResponse::class, 'invite_id');
    }
}

