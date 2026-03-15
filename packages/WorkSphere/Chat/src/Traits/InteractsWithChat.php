<?php

namespace WorkSphere\Chat\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait InteractsWithChat
{
    /**
     * Get all chats the model is a participant of (Package version).
     */
    public function pkgChats(): BelongsToMany
    {
        return $this->belongsToMany(
            config('chat.models.chat'),
            config('chat.tables.participants'),
            'user_id',
            'chat_id'
        )->withPivot(['role', 'last_read_message_id'])->withTimestamps();
    }

    /**
     * Get all messages sent by the model (Package version).
     */
    public function pkgMessages(): HasMany
    {
        return $this->hasMany(config('chat.models.message'), 'user_id');
    }
}
