<?php

namespace App\Models;

use App\Models\Chat\Chat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Team extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\TeamFactory> */
    use HasFactory;

    use InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'avatar',
        'owner_id',
        'status',
        'settings',
        'last_activity_at',
        'lifecycle_status',
        'dormant_notified_at',
        'deletion_scheduled_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'owner_id',
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var list<string>
     */
    protected $appends = [
        'member_count',
        'initials',
        'avatar_url',
        'storage_limit',
        'has_avatar',
    ];

    /**
     * Check if the team has a custom avatar.
     */
    public function getHasAvatarAttribute(): bool
    {
        return $this->hasMedia('avatars');
    }

    /**
     * Get the total storage used by team files in bytes.
     */
    public function getStorageUsedAttribute(): int
    {
        return $this->media()->sum('size');
    }

    /**
     * Get the storage limit in bytes.
     */
    public function getStorageLimitAttribute(): int
    {
        $limitMb = app(\App\Services\AppSettingsService::class)->get('storage.max_team_storage', 1024);

        return $limitMb * 1024 * 1024;
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team): void {
            if (empty($team->public_id)) {
                $team->public_id = (string) Str::uuid();
            }

            if (empty($team->slug)) {
                $team->slug = self::generateSlug($team->name);
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_activity_at' => 'datetime',
            'dormant_notified_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
        ];
    }

    /**
     * Generate a unique slug from name.
     */
    public static function generateSlug(string $name): string
    {
        $baseSlug = Str::slug($name);

        if (strlen($baseSlug) < 2) {
            $baseSlug = 'team';
        }

        $baseSlug = Str::limit($baseSlug, 50, '');

        $slug = $baseSlug;
        $counter = 1;

        while (self::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get the team owner.
     *
     * @return BelongsTo<User, Team>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get the team members.
     *
     * @return BelongsToMany<User>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot(['role', 'team_role_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Get the custom roles for this team.
     *
     * @return HasMany<TeamRole>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(TeamRole::class);
    }

    /**
     * Get the projects for this team.
     *
     * @return HasMany<Project>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get the chats for this team.
     *
     * @return HasMany<Chat>
     */
    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    /**
     * Get the calendar events for this team.
     *
     * @return HasMany<TeamEvent>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TeamEvent::class);
    }

    /**
     * Get the default role for this team.
     */
    public function getDefaultRole(): ?TeamRole
    {
        return $this->roles()->where('is_default', true)->first();
    }

    /**
     * Create default roles for this team.
     */
    public function createDefaultRoles(?User $creator = null): void
    {
        $defaultRoles = [
            [
                'name' => 'Team Lead',
                'slug' => 'team_lead',
                'description' => 'Full team control and management',
                'color' => 'purple',
                'level' => 100,
                'is_system' => true,
                'permissions' => config('roles.team_role_permissions.team_lead', []),
            ],
            [
                'name' => 'Subject Matter Expert',
                'slug' => 'subject_matter_expert',
                'description' => 'Team administration and expert guidance',
                'color' => 'blue',
                'level' => 75,
                'is_system' => true,
                'permissions' => config('roles.team_role_permissions.subject_matter_expert', []),
            ],
            [
                'name' => 'Quality Assessor',
                'slug' => 'quality_assessor',
                'description' => 'Quality assurance and review',
                'color' => 'orange',
                'level' => 50,
                'is_system' => true,
                'permissions' => config('roles.team_role_permissions.quality_assessor', []),
            ],
            [
                'name' => 'Operator',
                'slug' => 'operator',
                'description' => 'Standard operator access',
                'color' => 'green',
                'level' => 25,
                'is_default' => true,
                'is_system' => true,
                'permissions' => config('roles.team_role_permissions.operator', []),
            ],
        ];

        foreach ($defaultRoles as $roleData) {
            $permissions = $roleData['permissions'] ?? [];
            unset($roleData['permissions']);

            $role = $this->roles()->create([
                ...$roleData,
                'created_by' => $creator?->id,
            ]);

            foreach ($permissions as $permission) {
                $role->permissions()->create(['permission' => $permission]);
            }
        }
    }

    /**
     * Get the member count attribute.
     */
    public function getMemberCountAttribute(): int
    {
        return $this->members()->count();
    }

    /**
     * Get the team initials.
     */
    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->name);
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }

        return $initials ?: 'T';
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
     * Get full avatar data from AvatarService.
     *
     * @param  string  $variant  'optimized' or 'thumb'
     */
    public function getAvatarData(string $variant = 'optimized'): \App\Contracts\AvatarData
    {
        return app(\App\Contracts\AvatarContract::class)->resolve($this, $variant);
    }

    /**
     * Check if user is a member of the team.
     */
    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Check if user is an admin of the team.
     */
    public function hasAdmin(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->whereIn('role', ['team_lead', 'subject_matter_expert'])
            ->exists();
    }

    /**
     * Check if user is the owner of the team.
     */
    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /**
     * Add a member to the team.
     */
    public function addMember(User $user, string $role = 'operator'): void
    {
        if (! $this->hasMember($user)) {
            $teamRole = $this->roles()->where('slug', $role)->first();

            $this->members()->attach($user->id, [
                'role' => $role,
                'team_role_id' => $teamRole ? $teamRole->id : null,
                'joined_at' => now(),
            ]);
        }
    }

    /**
     * Remove a member from the team.
     */
    public function removeMember(User $user): void
    {
        $this->members()->detach($user->id);
    }

    /**
     * Update a member's role.
     */
    public function updateMemberRole(User $user, string $role): void
    {
        $this->members()->updateExistingPivot($user->id, ['role' => $role]);
    }

    /**
     * Scope: Only active teams.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: Teams for a specific user.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });
    }

    /**
     * Scope: Dormant teams.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public function scopeDormant(Builder $query): Builder
    {
        return $query->where('lifecycle_status', 'dormant');
    }

    /**
     * Scope: Teams pending deletion.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public function scopePendingDeletion(Builder $query): Builder
    {
        return $query->where('lifecycle_status', 'pending_deletion');
    }

    /**
     * Scope: Active lifecycle teams.
     *
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    public function scopeLifecycleActive(Builder $query): Builder
    {
        return $query->where('lifecycle_status', 'active');
    }

    /**
     * Touch the team's activity timestamp.
     */
    public function touchActivity(): void
    {
        $this->update([
            'last_activity_at' => now(),
            'lifecycle_status' => 'active',
            'dormant_notified_at' => null,
            'deletion_scheduled_at' => null,
        ]);
    }

    /**
     * Mark the team as dormant.
     */
    public function markDormant(): void
    {
        $this->update([
            'lifecycle_status' => 'dormant',
            'dormant_notified_at' => now(),
        ]);
    }

    /**
     * Mark the team as pending deletion.
     */
    public function markPendingDeletion(): void
    {
        $graceDays = config('teams.health.deletion_grace_days', 30);

        $this->update([
            'lifecycle_status' => 'pending_deletion',
            'deletion_scheduled_at' => now()->addDays($graceDays),
        ]);
    }

    /**
     * Keep the team active (reset lifecycle status).
     */
    public function keepActive(): void
    {
        $this->touchActivity();
    }

    /**
     * Check if team is dormant.
     */
    public function isDormant(): bool
    {
        return $this->lifecycle_status === 'dormant';
    }

    /**
     * Check if team is pending deletion.
     */
    public function isPendingDeletion(): bool
    {
        return $this->lifecycle_status === 'pending_deletion';
    }

    use \Laravel\Scout\Searchable;

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
        ];
    }

    /**
     * Register media collections.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatars')
            ->useDisk('public') // Force public disk
            ->singleFile() // Ensures only one avatar exists
            ->useFallbackUrl(config('app.url').'/images/defaults/team-avatar.png'); // Optional: Add default fallback
    }

    /**
     * Register the media conversions.
     */
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
    }
}
