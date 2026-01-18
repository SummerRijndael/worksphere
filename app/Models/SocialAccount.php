<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'provider_email',
        'provider_avatar',
        'provider_name',
        'provider_data',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_data' => 'array',
            'scopes' => 'array',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns this social account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get a formatted provider name for display.
     */
    public function getDisplayNameAttribute(): string
    {
        return match ($this->provider) {
            'google' => 'Google',
            'github' => 'GitHub',
            'facebook' => 'Facebook',
            'meta' => 'Meta',
            'twitter' => 'Twitter',
            'linkedin' => 'LinkedIn',
            'microsoft' => 'Microsoft',
            default => ucfirst($this->provider),
        };
    }
}
