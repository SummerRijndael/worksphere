<?php

namespace WorkSphere\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use WorkSphere\Chat\Contracts\ChatParticipant;

class Chat extends Model
{
    protected $fillable = [
        'public_id',
        'name',
        'type',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable()
    {
        return config('chat.tables.chats', 'pkg_chats');
    }

    /**
     * Relationship to participants.
     */
    public function participants(): BelongsToMany
    {
        $userModel = config('chat.models.user');
        
        return $this->belongsToMany($userModel, config('chat.tables.participants'))
            ->withPivot(['role', 'last_read_message_id'])
            ->withTimestamps();
    }

    /**
     * Relationship to messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(config('chat.models.message'));
    }
}
