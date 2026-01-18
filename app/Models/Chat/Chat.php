<?php

namespace App\Models\Chat;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Chat extends Model implements HasMedia
{
    use HasFactory, \Illuminate\Database\Eloquent\Concerns\HasUlids, InteractsWithMedia, SoftDeletes;

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public const TYPE_DM = 'dm';

    public const TYPE_GROUP = 'group';

    public const TYPE_TEAM = 'team';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'name',
        'avatar',
        'type',
        'created_by',
        'team_id',
        'is_primary',
        'marked_for_deletion_at',
        'last_activity_at',
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var list<string>
     */
    protected $appends = ['avatar_url'];

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * Get the messages for this chat (latest first).
     *
     * @return HasMany<ChatMessage>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->latest('id');
    }

    /**
     * Get the latest message in this chat.
     *
     * @return HasOne<ChatMessage>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    /**
     * Alias for latestMessage (backwards compatibility).
     *
     * @return HasOne<ChatMessage>
     */
    public function lastMessage(): HasOne
    {
        return $this->latestMessage();
    }

    /**
     * Get the participants of the chat with pivot data.
     *
     * @return BelongsToMany<User>
     */
    /**
     * Get the active participants of the chat (currently in group).
     *
     * @return BelongsToMany<User>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants', 'chat_id', 'user_id')
            ->wherePivot('left_at', null)
            ->withPivot('last_read_message_id', 'role', 'left_at')
            ->withTimestamps();
    }

    /**
     * Get all participants including those who left (for message history).
     *
     * @return BelongsToMany<User>
     */
    public function allParticipants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants', 'chat_id', 'user_id')
            ->withPivot('last_read_message_id', 'role', 'left_at', 'kicked_by')
            ->withTimestamps();
    }

    /**
     * Get the team this chat belongs to.
     *
     * @return BelongsTo<Team, Chat>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created this chat.
     *
     * @return BelongsTo<User, Chat>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the owner of the group chat.
     *
     * @return BelongsToMany<User>
     */
    public function owner(): BelongsToMany
    {
        return $this->participants()->wherePivot('role', 'owner');
    }

    /**
     * Check if user is the owner of this chat.
     */
    public function isOwner(User $user): bool
    {
        return $this->participants()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'owner')
            ->exists();
    }

    /**
     * Get the count of active participants.
     */
    public function activeParticipantCount(): int
    {
        return $this->participants()->count();
    }

    /**
     * Accessor for avatar URL with fallback.
     *
     * @deprecated Use getAvatarData() for full avatar info
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getAvatarData()->getUrl()
        );
    }

    /**
     * Get full avatar data from AvatarService.
     *
     * Handles DM chats (other participant's avatar), group chats (composite/uploaded),
     * and team chats appropriately.
     *
     * @param  string  $variant  'optimized' or 'thumb'
     */
    public function getAvatarData(string $variant = 'optimized'): \App\Contracts\AvatarData
    {
        return app(\App\Contracts\AvatarContract::class)->resolve($this, $variant);
    }

    /**
     * Check if this is a direct message chat.
     */
    public function isDm(): bool
    {
        return $this->type === self::TYPE_DM;
    }

    /**
     * Check if this is a group chat.
     */
    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    /**
     * Check if this is a team chat.
     */
    public function isTeam(): bool
    {
        return $this->type === self::TYPE_TEAM;
    }

    /**
     * Get the other participant in a DM chat.
     */
    public function getOtherParticipant(User $currentUser): ?User
    {
        if (! $this->isDm()) {
            return null;
        }

        return $this->participants->firstWhere('id', '!=', $currentUser->id);
    }

    /**
     * Calculate unread count for a specific user.
     */
    public function unreadCountFor(User $user): int
    {
        $participant = $this->participants->find($user->id);

        if (! $participant) {
            return 0;
        }

        $lastReadId = $participant->pivot->last_read_message_id ?? 0;

        return $this->messages()
            ->where('id', '>', $lastReadId)
            ->where('user_id', '!=', $user->id)
            ->count();
    }
}
