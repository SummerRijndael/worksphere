<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatInvite extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const TYPE_DM = 'dm';

    public const TYPE_GROUP = 'group';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'inviter_id',
        'invitee_id',
        'chat_id',
        'type',
        'status',
        'expires_at',
        'responded_at',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ChatInvite $invite) {
            if (empty($invite->public_id)) {
                $invite->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * Get the route key name for Laravel routing.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * Get the user who sent the invite.
     *
     * @return BelongsTo<User, ChatInvite>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * Get the user who received the invite.
     *
     * @return BelongsTo<User, ChatInvite>
     */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    /**
     * Get the chat this invite is for (group invites only).
     *
     * @return BelongsTo<Chat, ChatInvite>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Scope: Only pending invites.
     *
     * @param  Builder<ChatInvite>  $query
     * @return Builder<ChatInvite>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Invites between two users for DM.
     *
     * @param  Builder<ChatInvite>  $query
     * @return Builder<ChatInvite>
     */
    public function scopeBetween(Builder $query, int $userA, int $userB): Builder
    {
        return $query->where(function (Builder $sub) use ($userA, $userB) {
            $sub->where('inviter_id', $userA)
                ->where('invitee_id', $userB);
        })->orWhere(function (Builder $sub) use ($userA, $userB) {
            $sub->where('inviter_id', $userB)
                ->where('invitee_id', $userA);
        })->whereNull('chat_id')->where('type', 'dm');
    }

    /**
     * Scope: Invites for a specific chat.
     *
     * @param  Builder<ChatInvite>  $query
     * @return Builder<ChatInvite>
     */
    public function scopeForChat(Builder $query, int $chatId): Builder
    {
        return $query->where('chat_id', $chatId);
    }

    /**
     * Purge expired pending invites.
     */
    public static function purgeExpired(): void
    {
        static::pending()
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * Mark this invite as accepted.
     */
    public function markAccepted(): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACCEPTED,
            'responded_at' => now(),
        ])->save();
    }

    /**
     * Mark this invite as rejected.
     */
    public function markRejected(): void
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'responded_at' => now(),
        ])->save();
    }

    /**
     * Check if this invite is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this invite has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
