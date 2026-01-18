<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmailLabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'color',
    ];

    // ==================
    // Relationships
    // ==================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emails(): BelongsToMany
    {
        return $this->belongsToMany(Email::class, 'email_email_label');
    }

    // ==================
    // Scopes
    // ==================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
