<?php

namespace App\Models;

use App\Models\Chat\Chat;
use App\Traits\Auditable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia, MustVerifyEmail, WebAuthnAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable, TwoFactorAuthenticatable, WebAuthnAuthentication;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatars')
            ->singleFile()
            ->useDisk('public');

        $this->addMediaCollection('cover_photos')
            ->singleFile()
            ->useDisk('public');

        $this->addMediaCollection('documents')
            ->useDisk('private'); // Private disk for documents (persists on cloud)
    }

    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(100)
            ->height(100)
            ->sharpen(10)
            ->format('webp')
            ->optimize();

        $this->addMediaConversion('optimized')
            ->width(800)
            ->height(800)
            ->format('webp')
            ->optimize();

        $this->addMediaConversion('cover_optimized')
            ->fit(\Spatie\Image\Enums\Fit::Crop, 1200, 400)
            ->format('webp')
            ->optimize()
            ->performOnCollections('cover_photos');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'is_public',
        'email',
        'password',
        // 'avatar' - DEPRECATED: Use Media Library via AvatarService
        'title',
        'bio',
        'location',
        'website',
        'skills',
        'status',
        'status_reason',
        'suspended_until',
        'preferences',
        'provider',
        'provider_id',
        'phone',
        'phone_verified_at',
        'two_factor_sms_enabled',
        'two_factor_sms_confirmed_at',
        'two_factor_email_enabled',
        'two_factor_enforced',
        'two_factor_allowed_methods',
        'two_factor_enforced_by',
        'two_factor_enforced_at',
        'is_password_set',
        'password_last_updated_at',
        'presence_preference',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'password',
        'remember_token',
        'provider',
        'provider_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var list<string>
     */
    protected $appends = [
        'display_name',
        'initials',
        'avatar_url',
        'avatar_thumb_url',
        'cover_photo_url',
        'has_avatar',
    ];

    /**
     * Check if the user has a custom avatar.
     */
    public function getHasAvatarAttribute(): bool
    {
        return $this->hasMedia('avatars');
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (User $user): void {
            if (empty($user->public_id)) {
                $user->public_id = (string) Str::uuid();
            }

            if (empty($user->username)) {
                $user->username = self::generateUsername($user->name, $user->email);
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        // Use the WelcomeEmailNotification which now contains the verification link logic.
        // This ensures consistent branding and consolidation.
        $this->notify(new \App\Notifications\WelcomeEmailNotification(false, null, 'Verify Email'));
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\SystemResetPassword($token));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'notification_preferences' => 'array',
            'skills' => 'array',
            'last_login_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_sms_enabled' => 'boolean',
            'two_factor_sms_confirmed_at' => 'datetime',
            'two_factor_email_enabled' => 'boolean',
            'two_factor_enforced' => 'boolean',
            'two_factor_allowed_methods' => 'array',
            'two_factor_enforced_at' => 'datetime',
            'suspended_until' => 'datetime',
            'is_password_set' => 'boolean',
            'password_last_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Interact with the user's name.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? ucwords(strtolower($value)) : null,
            set: fn (string $value) => ucwords(strtolower($value)),
        );
    }

    /**
     * Interact with the user's location.
     */
    protected function location(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? ucwords(strtolower($value)) : null,
            set: fn (?string $value) => $value ? ucwords(strtolower($value)) : null,
        );
    }

    /**
     * Generate a unique username from name or email.
     */
    public static function generateUsername(string $name, string $email): string
    {
        // Try to create username from name first
        $baseUsername = Str::slug(Str::lower($name), '_');

        if (strlen($baseUsername) < 3) {
            // Fall back to email prefix
            $baseUsername = Str::before($email, '@');
            $baseUsername = Str::slug(Str::lower($baseUsername), '_');
        }

        // Ensure minimum length
        if (strlen($baseUsername) < 3) {
            $baseUsername = 'user';
        }

        // Truncate if too long
        $baseUsername = Str::limit($baseUsername, 40, '');

        // Make unique
        $username = $baseUsername;
        $counter = 1;

        while (self::where('username', $username)->exists()) {
            $username = $baseUsername.'_'.$counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Check if the user account is active and can login.
     */
    public function canLogin(): bool
    {
        // Treat null or empty status as 'active' (database default)
        $status = $this->status ?? 'active';

        // Check strict blocking statuses first
        if (in_array($status, ['blocked', 'banned'])) {
            return false;
        }

        // Check suspension
        if ($status === 'suspended') {
            if ($this->suspended_until && $this->suspended_until->isFuture()) {
                return false;
            }
            // If suspension expired, technically they can login,
            // but maybe we should auto-activate? For now, let's allow if expired.
            if ($this->suspended_until && $this->suspended_until->isPast()) {
                // Auto-activate or allow? Let's just return true if expired.
                return true;
            }

            // If suspended without date, block
            return false;
        }

        $statuses = config('roles.statuses', []);
        $statusConfig = $statuses[$status] ?? null;

        // If status is not in config, check if it's 'active' (safe default)
        if ($statusConfig === null) {
            // Log unexpected status for debugging
            if ($status !== 'active') {
                \Illuminate\Support\Facades\Log::warning('Unknown user status encountered', [
                    'user_id' => $this->id,
                    'status' => $status,
                ]);
            }

            // Default: allow login for 'active', deny for unknown statuses
            return $status === 'active';
        }

        return $statusConfig['can_login'] ?? false;
    }

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if user wants email notifications for a specific type.
     *
     * @param  string  $type  Notification type (e.g., 'ticket_created', 'ticket_updated')
     */
    public function wantsEmailFor(string $type): bool
    {
        $defaults = [
            'ticket_created' => true,
            'ticket_updated' => true,
            'ticket_comment' => true,
            'ticket_sla' => true,
            'ticket_assigned' => true,
        ];

        $prefs = $this->notification_preferences ?? [];

        return $prefs[$type] ?? ($defaults[$type] ?? true);
    }

    /**
     * Update notification preference for a specific type.
     */
    public function setNotificationPreference(string $type, bool $enabled): void
    {
        $prefs = $this->notification_preferences ?? [];
        $prefs[$type] = $enabled;
        $this->notification_preferences = $prefs;
        $this->save();
    }

    /**
     * Get the user's display name (first name or full name).
     */
    public function getDisplayNameAttribute(): string
    {
        return explode(' ', $this->name)[0];
    }

    /**
     * Get the user's initials.
     */
    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->name);
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }

        return $initials;
    }

    /**
     * Get avatar URL with fallback.
     *
     * @deprecated Use getAvatarData() for full avatar info
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->getAvatarData()->getUrl();
    }

    /**
     * Get avatar thumbnail URL with fallback.
     *
     * @deprecated Use getAvatarData('thumb') for full avatar info
     */
    public function getAvatarThumbUrlAttribute(): ?string
    {
        return $this->getAvatarData('thumb')->getUrl();
    }

    /**
     * Get full avatar data from AvatarService.
     *
     * @param  string  $variant  'optimized' or 'thumb'
     */
    public function getAvatarData(string $variant = 'optimized'): \App\Contracts\AvatarData
    {
        return app(\App\Contracts\AvatarContract::class)->resolve($this, $variant);
    }

    /**
     * Get cover photo URL.
     */
    public function getCoverPhotoUrlAttribute(): ?string
    {
        if ($this->hasMedia('cover_photos')) {
            return $this->getFirstMediaUrl('cover_photos', 'cover_optimized');
        }

        return null;
    }

    /**
     * Get a specific preference value.
     */
    public function getPreference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    /**
     * Set a specific preference value.
     */
    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->preferences ?? [];
        data_set($preferences, $key, $value);
        $this->preferences = $preferences;
    }

    /**
     * Record a login event.
     */
    public function recordLogin(?string $ipAddress = null): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ipAddress,
        ]);
    }

    /**
     * Get the user's role level for comparison.
     */
    public function getRoleLevelAttribute(): int
    {
        $roles = config('roles.roles', []);
        $maxLevel = 0;

        foreach ($this->getRoleNames() as $roleName) {
            $roleConfig = $roles[$roleName] ?? null;
            if ($roleConfig && ($roleConfig['level'] ?? 0) > $maxLevel) {
                $maxLevel = $roleConfig['level'];
            }
        }

        return $maxLevel;
    }

    /**
     * Check if user has a higher role level than another user.
     */
    public function hasHigherRoleThan(User $user): bool
    {
        return $this->role_level > $user->role_level;
    }

    /**
     * Scope: Only active users.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: Users that can login.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeCanLogin(Builder $query): Builder
    {
        $loginableStatuses = collect(config('roles.statuses', []))
            ->filter(fn ($status) => $status['can_login'] ?? false)
            ->keys()
            ->toArray();

        return $query->whereIn('status', $loginableStatuses);
    }

    // Relationships for permission overrides

    /**
     * Get user's permission overrides.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<PermissionOverride, $this>
     */
    public function permissionOverrides(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PermissionOverride::class);
    }

    /**
     * Get user's active permission overrides.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<PermissionOverride, $this>
     */
    public function activePermissionOverrides(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->permissionOverrides()->active();
    }

    /**
     * Get user's status change history.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<UserStatusChange, $this>
     */
    public function statusChanges(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserStatusChange::class);
    }

    /**
     * Get user's role change history.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<UserRoleChange, $this>
     */
    public function roleChanges(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserRoleChange::class);
    }

    /**
     * Check if user has any 2FA method configured.
     * Optionally filter by specific allowed methods.
     *
     * @param  array<string>|null  $methods  Allowed methods: 'totp', 'sms', 'email', 'passkey'
     */
    public function has2FAConfigured(?array $methods = null): bool
    {
        $hasTOTP = $this->two_factor_secret && $this->two_factor_confirmed_at;
        $hasSMS = $this->two_factor_sms_enabled && $this->phone;
        $hasEmail = $this->two_factor_email_enabled;
        $hasPasskey = $this->webauthnCredentials()->exists();

        if ($methods === null) {
            return $hasTOTP || $hasSMS || $hasEmail || $hasPasskey;
        }

        // Check if user has one of the allowed methods
        foreach ($methods as $method) {
            if ($method === 'totp' && $hasTOTP) {
                return true;
            }
            if ($method === 'sms' && $hasSMS) {
                return true;
            }
            if ($method === 'email' && $hasEmail) {
                return true;
            }
            if ($method === 'passkey' && $hasPasskey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user requires 2FA setup based on enforcement rules.
     *
     * @return false|array{required: bool, methods: array<string>, source: string, role?: string}
     */
    public function requires2FASetup(): false|array
    {
        // Check user-level enforcement first
        if ($this->two_factor_enforced) {
            $methods = $this->two_factor_allowed_methods ?? ['totp', 'sms', 'email', 'passkey'];
            if (! $this->has2FAConfigured($methods)) {
                return [
                    'required' => true,
                    'methods' => $methods,
                    'source' => 'user',
                ];
            }
        }

        // Check role-level enforcement
        foreach ($this->roles as $role) {
            $enforcement = RoleTwoFactorEnforcement::where('role_id', $role->id)
                ->where('is_active', true)
                ->first();

            if ($enforcement) {
                $methods = $enforcement->allowed_methods;
                if (! $this->has2FAConfigured($methods)) {
                    return [
                        'required' => true,
                        'methods' => $methods,
                        'source' => 'role',
                        'role' => $role->name,
                    ];
                }
            }
        }

        return false;
    }

    /**
     * Get role change requests made by this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<RoleChangeRequest, $this>
     */
    public function roleChangeRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RoleChangeRequest::class, 'requested_by');
    }

    /**
     * Get role change approvals made by this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<RoleChangeApproval, $this>
     */
    public function roleChangeApprovals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RoleChangeApproval::class, 'admin_id');
    }

    /**
     * Get the teams that the user belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Team>
     */
    public function teams(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Get the chats that the user participates in.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Chat>
     */
    public function chats(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_participants', 'user_id', 'chat_id')
            ->withPivot(['last_read_message_id', 'role'])
            ->withTimestamps();
    }

    /**
     * Get events organized by the user.
     */
    public function organizedEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Event::class, 'user_id');
    }

    /**
     * Get the calendar shares where this user is the owner (sharing with others).
     */
    public function calendarShares(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        // Since we don't have a CalendarShare model yet, we can define the relationship manually or create the model.
        // For simplicity in Policy, we used the relationship. Let's create a stub model or use DB query in Policy.
        // UserPolicy used $calendarOwner->calendarShares().
        // To make Eloquent work, we need a CalendarShare model.
        // Let's create the model file next.
        return $this->hasMany(CalendarShare::class);
    }

    /**
     * Get events the user is attending.
     */
    public function attendingEvents(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_attendees')
            ->using(EventAttendee::class)
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * Get the user's notes.
     */
    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * The channels the user receives notification broadcasts on.
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'App.Models.User.'.$this->public_id;
    }

    // ==================== Social Accounts ====================

    /**
     * Get user's connected social accounts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * Check if user has a specific social provider connected.
     */
    public function hasSocialProvider(string $provider): bool
    {
        // Check new social_accounts table first
        if ($this->socialAccounts()->where('provider', $provider)->exists()) {
            return true;
        }

        // Legacy: check provider column on users table
        return $this->provider === $provider;
    }

    /**
     * Get a specific social account by provider.
     */
    public function getSocialAccount(string $provider): ?SocialAccount
    {
        return $this->socialAccounts()->where('provider', $provider)->first();
    }

    /**
     * Get all connected provider names.
     *
     * @return array<string>
     */
    public function getConnectedProviders(): array
    {
        $providers = $this->socialAccounts()->pluck('provider')->toArray();

        // Include legacy provider if set
        if ($this->provider && ! in_array($this->provider, $providers)) {
            $providers[] = $this->provider;
        }

        return $providers;
    }

    // ==================== Client Portal ====================

    /**
     * Get the linked client for this user (by email match).
     */
    public function getLinkedClientAttribute(): ?Client
    {
        return Client::where('email', $this->email)->first();
    }

    /**
     * Check if the user is a client user.
     */
    public function isClient(): bool
    {
        return $this->hasRole('client');
    }

    /**
     * Get projects where the user is assigned as a client.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Project>
     */
    public function getClientProjectsAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        $client = $this->linked_client;

        if (! $client) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return Project::where('client_id', $client->id)->get();
    }
    // ==================
    // Email System
    // ==================

    public function emailAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailAccount::class);
    }

    public function emailFolders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailFolder::class);
    }

    public function emailSignatures(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailSignature::class);
    }

    public function emailTemplates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function emailLabels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailLabel::class);
    }
}
