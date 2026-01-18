<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailFolder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'email_account_id',
        'name',
        'slug',
        'color',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    // ==================
    // Boot
    // ==================

    protected static function booted(): void
    {
        static::creating(function (EmailFolder $folder) {
            if (empty($folder->slug)) {
                $folder->slug = Str::slug($folder->name).'-'.Str::random(6);
            }
        });
    }

    // ==================
    // Relationships
    // ==================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    // ==================
    // Scopes
    // ==================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
