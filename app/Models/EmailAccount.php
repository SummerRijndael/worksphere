<?php

namespace App\Models;

use App\Enums\EmailSyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class EmailAccount extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    public function getRouteKeyName()
    {
        return 'public_id';
    }

    protected $fillable = [
        'public_id',
        'user_id',
        'team_id',
        'name',
        'email',
        'provider',
        'auth_type',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'username',
        'password',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_active',
        'is_verified',
        'is_default',
        'is_system',
        'last_used_at',
        'last_error',
        'sync_status',
        'initial_sync_completed_at',
        'last_sync_at',
        'last_synced_uid',
        'sync_cursor',
        'sync_error',
        'needs_reauth',
        'consecutive_failures',
        'storage_used',
        'storage_limit',
        'storage_updated_at',
    ];

    protected $casts = [
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'token_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'initial_sync_completed_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_synced_uid' => 'integer',
        'storage_used' => 'integer',
        'storage_limit' => 'integer',
        'storage_updated_at' => 'datetime',
        'sync_cursor' => 'array',
        'sync_status' => EmailSyncStatus::class,
        'needs_reauth' => 'boolean',
        'consecutive_failures' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    protected $hidden = [
        'password',
        'access_token',
        'refresh_token',
    ];

    /**
     * Provider configurations.
     */
    public const PROVIDERS = [
        'gmail' => [
            'name' => 'Gmail',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'supports_oauth' => true,
        ],
        'outlook' => [
            'name' => 'Outlook',
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'supports_oauth' => true,
        ],
        'custom' => [
            'name' => 'Custom IMAP/SMTP',
            'supports_oauth' => false,
        ],
    ];

    // ==================
    // Encrypted Accessors
    // ==================

    public function getPasswordAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getRefreshTokenAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setRefreshTokenAttribute($value): void
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    // ==================
    // Relationships
    // ==================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(EmailSyncLog::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(EmailFolder::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(EmailSignature::class);
    }

    // ==================
    // Scopes
    // ==================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopePersonal($query)
    {
        return $query->whereNotNull('user_id')->whereNull('team_id');
    }

    public function scopeShared($query)
    {
        return $query->whereNotNull('team_id');
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeUserAccounts($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeNeedsSync($query)
    {
        return $query->whereIn('sync_status', [
            EmailSyncStatus::Pending,
            EmailSyncStatus::Seeding,
            EmailSyncStatus::Syncing,
        ]);
    }

    public function scopeSyncCompleted($query)
    {
        return $query->where('sync_status', EmailSyncStatus::Completed);
    }

    // ==================
    // Helpers
    // ==================

    public function isOAuth(): bool
    {
        return $this->auth_type === 'oauth';
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expires_at) {
            return true;
        }

        return $this->token_expires_at->isPast();
    }

    public function needsTokenRefresh(): bool
    {
        if (! $this->isOAuth()) {
            return false;
        }

        // Refresh if token expires in less than 5 minutes
        return ! $this->token_expires_at || $this->token_expires_at->subMinutes(5)->isPast();
    }

    public function markAsVerified(): void
    {
        $this->update([
            'is_verified' => true,
            'last_error' => null,
        ]);
    }

    public function markAsError(string $error): void
    {
        $this->update([
            'is_verified' => false,
            'last_error' => $error,
        ]);
    }

    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Get the provider configuration.
     */
    public function getProviderConfig(): array
    {
        return self::PROVIDERS[$this->provider] ?? self::PROVIDERS['custom'];
    }

    /**
     * Check if this is a personal account (belongs to user).
     */
    public function isPersonal(): bool
    {
        return $this->user_id !== null && $this->team_id === null;
    }

    /**
     * Check if this is a shared account (belongs to team).
     */
    public function isShared(): bool
    {
        return $this->team_id !== null;
    }
}
