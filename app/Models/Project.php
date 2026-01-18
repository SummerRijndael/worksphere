<?php

namespace App\Models;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Project extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use Searchable;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'description',
        'status',
        'priority',
        'start_date',
        'due_date',
        'completed_at',
        'client_id',
        'budget',
        'currency',
        'progress_percentage',
        'settings',
        'created_by',
        'archived_at',
        'archived_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'team_id',
        'client_id',
        'created_by',
        'archived_by',
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var list<string>
     */
    protected $appends = [
        'member_count',
        'is_overdue',
        'days_until_due',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Project $project): void {
            if (empty($project->public_id)) {
                $project->public_id = (string) Str::uuid();
            }

            if (empty($project->slug)) {
                $project->slug = self::generateSlug($project->name, $project->team_id);
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
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'budget' => 'decimal:2',
            'progress_percentage' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * Generate a unique slug from name within the team.
     */
    public static function generateSlug(string $name, int $teamId): string
    {
        $baseSlug = Str::slug($name);

        if (strlen($baseSlug) < 2) {
            $baseSlug = 'project';
        }

        $baseSlug = Str::limit($baseSlug, 50, '');

        $slug = $baseSlug;
        $counter = 1;

        while (self::where('slug', $slug)->where('team_id', $teamId)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Register media collections.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk('private');

        $this->addMediaCollection('gallery')
            ->useDisk('private')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/svg+xml',
            ]);
    }

    /**
     * Register media conversions.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->sharpen(10)
            ->performOnCollections('gallery');

        $this->addMediaConversion('preview')
            ->width(800)
            ->height(600)
            ->performOnCollections('gallery');
    }

    /**
     * Get the team that owns this project.
     *
     * @return BelongsTo<Team, Project>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the client associated with this project.
     *
     * @return BelongsTo<Client, Project>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the user who created this project.
     *
     * @return BelongsTo<User, Project>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who archived this project.
     *
     * @return BelongsTo<User, Project>
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Get the project members.
     *
     * @return BelongsToMany<User>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Get the tasks for this project.
     *
     * @return HasMany<Task>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get the invoices for this project.
     *
     * @return HasMany<Invoice>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the member count attribute.
     */
    public function getMemberCountAttribute(): int
    {
        return $this->members()->count();
    }

    /**
     * Check if project is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (! $this->due_date) {
            return false;
        }

        if ($this->status->isTerminal()) {
            return false;
        }

        return $this->due_date->isPast();
    }

    /**
     * Get days until due date.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) now()->diffInDays($this->due_date, false);
    }

    /**
     * Check if user is a member of this project.
     */
    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Check if user is a manager of this project.
     */
    public function hasManager(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('role', 'manager')
            ->exists();
    }

    /**
     * Add a member to the project.
     */
    public function addMember(User $user, string $role = 'member'): void
    {
        if (! $this->hasMember($user)) {
            $this->members()->attach($user->id, [
                'role' => $role,
                'joined_at' => now(),
            ]);
        }
    }

    /**
     * Remove a member from the project.
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
     * Archive the project.
     */
    public function archive(User $archivedBy): void
    {
        $this->update([
            'status' => ProjectStatus::Archived,
            'archived_at' => now(),
            'archived_by' => $archivedBy->id,
        ]);
    }

    /**
     * Unarchive the project.
     */
    public function unarchive(): void
    {
        $this->update([
            'status' => ProjectStatus::Active,
            'archived_at' => null,
            'archived_by' => null,
        ]);
    }

    /**
     * Mark project as completed.
     */
    public function complete(): void
    {
        $this->update([
            'status' => ProjectStatus::Completed,
            'completed_at' => now(),
            'progress_percentage' => 100,
        ]);
    }

    /**
     * Calculate and update progress based on tasks.
     */
    public function recalculateProgress(): void
    {
        $totalTasks = $this->tasks()->count();

        if ($totalTasks === 0) {
            $this->update(['progress_percentage' => 0]);

            return;
        }

        $completedTasks = $this->tasks()->where('status', 'completed')->count();
        $percentage = (int) round(($completedTasks / $totalTasks) * 100);

        $this->update(['progress_percentage' => $percentage]);
    }

    /**
     * Scope: Only active projects.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active);
    }

    /**
     * Scope: Only archived projects.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Archived);
    }

    /**
     * Scope: Projects for a specific team.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    /**
     * Scope: Projects for a specific user (as member).
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });
    }

    /**
     * Scope: Overdue projects.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', [ProjectStatus::Completed, ProjectStatus::Archived]);
    }

    /**
     * Scope: Projects due soon (within X days).
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeDueSoon(Builder $query, int $days = 7): Builder
    {
        return $query->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDays($days)])
            ->whereNotIn('status', [ProjectStatus::Completed, ProjectStatus::Archived]);
    }

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
            'status' => $this->status?->value,
            'priority' => $this->priority?->value,
        ];
    }
}
