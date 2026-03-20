<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupportSurveyResponse extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'invite_id',
        'conversation_id',
        'requester_user_id',
        'rated_agent_user_id',
        'survey_type',
        'score',
        'label',
        'comment',
        'channel',
        'submitted_from_ip',
        'submitted_user_agent',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportSurveyResponse $response): void {
            if (empty($response->public_id)) {
                $response->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<SupportSurveyInvite, SupportSurveyResponse>
     */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(SupportSurveyInvite::class, 'invite_id');
    }

    /**
     * @return BelongsTo<SupportConversation, SupportSurveyResponse>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, SupportSurveyResponse>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return BelongsTo<User, SupportSurveyResponse>
     */
    public function ratedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_agent_user_id');
    }
}

