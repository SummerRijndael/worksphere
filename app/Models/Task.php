<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Task extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'parent_id',
        'task_template_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'started_at',
        'submitted_at',
        'approved_at',
        'sent_to_client_at',
        'client_approved_at',
        'completed_at',
        'estimated_hours',
        'actual_hours',
        'assigned_to',
        'assigned_by',
        'assigned_at',
        'sort_order',
        'checklist',
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
        'project_id',
        'parent_id',
        'task_template_id',
        'assigned_to',
        'assigned_by',
        'created_by',
        'archived_by',
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var list<string>
     */
    protected $appends = [
        'is_overdue',
        'days_until_due',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Task $task): void {
            if (empty($task->public_id)) {
                $task->public_id = (string) Str::uuid();
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
            'status' => TaskStatus::class,
            'due_date' => 'date',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_to_client_at' => 'datetime',
            'client_approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'assigned_at' => 'datetime',
            'archived_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'sort_order' => 'integer',
            'checklist' => 'array',
        ];
    }

    /**
     * Register media collections.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk('private');
    }

    /**
     * Get the project.
     *
     * @return BelongsTo<Project, Task>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the parent task.
     *
     * @return BelongsTo<Task, Task>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /**
     * Get the subtasks.
     *
     * @return HasMany<Task>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    /**
     * Get the template used for this task.
     *
     * @return BelongsTo<TaskTemplate, Task>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    /**
     * Get the assigned user.
     *
     * @return BelongsTo<User, Task>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who assigned this task.
     *
     * @return BelongsTo<User, Task>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Get the creator.
     *
     * @return BelongsTo<User, Task>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who archived this task.
     *
     * @return BelongsTo<User, Task>
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Get the status history.
     *
     * @return HasMany<TaskStatusHistory>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the QA reviews.
     *
     * @return HasMany<TaskQaReview>
     */
    public function qaReviews(): HasMany
    {
        return $this->hasMany(TaskQaReview::class);
    }

    /**
     * Get the latest QA review.
     */
    public function latestQaReview(): ?TaskQaReview
    {
        return $this->qaReviews()->latest()->first();
    }

    /**
     * Get the comments.
     *
     * @return HasMany<TaskComment>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the checklist items.
     *
     * @return HasMany<TaskChecklistItem>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position');
    }

    /**
     * Get checklist progress as "X/Y completed".
     */
    public function getChecklistProgressAttribute(): ?string
    {
        $total = $this->checklistItems()->count();
        if ($total === 0) {
            return null;
        }

        $done = $this->checklistItems()
            ->where('status', \App\Enums\TaskChecklistItemStatus::Done)
            ->count();

        return "{$done}/{$total}";
    }

    /**
     * Check if all checklist items are complete.
     */
    public function hasAllChecklistItemsComplete(): bool
    {
        $total = $this->checklistItems()->count();
        if ($total === 0) {
            return true; // No items means nothing to complete
        }

        $done = $this->checklistItems()
            ->where('status', \App\Enums\TaskChecklistItemStatus::Done)
            ->count();

        return $total === $done;
    }

    /**
     * Check if task can be submitted for review.
     */
    public function canSubmitForReview(): bool
    {
        return $this->hasAllChecklistItemsComplete()
            && $this->status === TaskStatus::InProgress;
    }

    /**
     * Check if task is overdue.
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
     * Check if the task is assigned.
     */
    public function isAssigned(): bool
    {
        return $this->assigned_to !== null;
    }

    /**
     * Check if the task is assigned to a specific user.
     */
    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_to === $user->id;
    }

    /**
     * Assign the task to a user.
     */
    public function assign(User $assignee, User $assigner): void
    {
        $this->update([
            'assigned_to' => $assignee->id,
            'assigned_by' => $assigner->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Unassign the task.
     */
    public function unassign(): void
    {
        $this->update([
            'assigned_to' => null,
            'assigned_by' => null,
            'assigned_at' => null,
        ]);
    }

    /**
     * Check if status transition is allowed.
     */
    public function canTransitionTo(TaskStatus $newStatus): bool
    {
        return $this->status->canTransitionTo($newStatus);
    }

    /**
     * Transition to a new status.
     */
    public function transitionTo(TaskStatus $newStatus, User $changedBy, ?string $notes = null): bool
    {
        if (! $this->canTransitionTo($newStatus)) {
            return false;
        }

        $oldStatus = $this->status;

        $this->update(['status' => $newStatus]);

        // Record status history
        TaskStatusHistory::create([
            'task_id' => $this->id,
            'from_status' => $oldStatus->value,
            'to_status' => $newStatus->value,
            'notes' => $notes,
            'changed_by' => $changedBy->id,
        ]);

        // Update timestamps based on status
        if ($newStatus === TaskStatus::InProgress && $this->started_at === null) {
            $this->update(['started_at' => now()]);
        }

        if ($newStatus === TaskStatus::Completed) {
            $this->update(['completed_at' => now()]);
        }

        return true;
    }

    /**
     * Archive the task.
     */
    public function archive(User $archivedBy): void
    {
        $this->update([
            'status' => TaskStatus::Archived,
            'archived_at' => now(),
            'archived_by' => $archivedBy->id,
        ]);
    }

    /**
     * Scope: Only tasks for a specific project.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeForProject(Builder $query, Project $project): Builder
    {
        return $query->where('project_id', $project->id);
    }

    /**
     * Scope: Only tasks assigned to a specific user.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->where('assigned_to', $user->id);
    }

    /**
     * Scope: Only root tasks (not subtasks).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeRootTasks(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: Overdue tasks.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Archived]);
    }

    /**
     * Scope: Tasks due soon.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeDueSoon(Builder $query, int $days = 7): Builder
    {
        return $query->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDays($days)])
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Archived]);
    }

    /**
     * Scope: Active tasks.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TaskStatus::Open,
            TaskStatus::InProgress,
            TaskStatus::Submitted,
            TaskStatus::InQa,
        ]);
    }

    /**
     * Scope: By status.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeWithStatus(Builder $query, TaskStatus $status): Builder
    {
        return $query->where('status', $status);
    }
}
