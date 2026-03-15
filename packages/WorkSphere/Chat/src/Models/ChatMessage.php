<?php

namespace WorkSphere\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'public_id',
        'chat_id',
        'user_id',
        'content',
        'type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable()
    {
        return config('chat.tables.messages', 'pkg_chat_messages');
    }

    /**
     * Relationship to chat.
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(config('chat.models.chat'));
    }

    /**
     * Relationship to sender.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(config('chat.models.user'), 'user_id');
    }
}
