<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupportGuestSession extends Model
{
    use HasFactory, MassPrunable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'conversation_id',
        'token_hash',
        'user_agent_hash',
        'ip_hash',
        'expires_at',
        'last_seen_at',
        'revoked_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportGuestSession $session): void {
            if (empty($session->public_id)) {
                $session->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<SupportConversation, SupportGuestSession>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * Prune revoked/expired guest sessions after the configured retention window.
     */
    public function prunable(): Builder
    {
        $retentionDays = max(1, (int) config('support_chat.guest_session_prune_days', 30));
        $cutoff = now()->subDays($retentionDays);

        return static::query()
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNotNull('revoked_at')
                    ->where('revoked_at', '<=', $cutoff)
                    ->orWhere('expires_at', '<=', $cutoff);
            });
    }
}
