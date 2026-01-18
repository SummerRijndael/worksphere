<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'subject',
        'body',
    ];

    // ==================
    // Relationships
    // ==================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================
    // Scopes
    // ==================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
