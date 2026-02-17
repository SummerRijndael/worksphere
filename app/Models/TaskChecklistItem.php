<?php

namespace App\Models;

use App\Enums\TaskChecklistItemStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskChecklistItem extends Model
{
    /** @use HasFactory<\Database\Factories\TaskChecklistItemFactory> */
    use Auditable, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'text',
        'status',
        'position',
        'started_at',
        'on_hold_at',
        'resumed_at',
        'reopened_at',
        'last_worked_on_by',
        'completed_by',
        'completed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'task_id',
        'completed_by',
        'last_worked_on_by',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TaskChecklistItem $item): void {
            if (empty($item->public_id)) {
                $item->public_id = (string) Str::uuid();
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
            'status' => TaskChecklistItemStatus::class,
            'position' => 'integer',
            'started_at' => 'datetime',
            'on_hold_at' => 'datetime',
            'resumed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the task this item belongs to.
     *
     * @return BelongsTo<Task, TaskChecklistItem>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who completed this item.
     *
     * @return BelongsTo<User, TaskChecklistItem>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Get the user who last worked on this item.
     *
     * @return BelongsTo<User, TaskChecklistItem>
     */
    public function lastWorkedOnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_worked_on_by');
    }

    /**
     * Mark the item as done.
     */
    public function markAsDone(User $user): void
    {
        $this->update([
            'status' => TaskChecklistItemStatus::Done,
            'completed_by' => $user->id,
            'completed_at' => now(),
        ]);
    }

    /**
     * Start the item.
     */
    public function start(User $user): void
    {
        $this->update([
            'status' => TaskChecklistItemStatus::InProgress,
            'started_at' => now(),
            'last_worked_on_by' => $user->id,
        ]);
    }

    /**
     * Put the item on hold.
     */
    public function putOnHold(): void
    {
        $this->update([
            'status' => TaskChecklistItemStatus::OnHold,
            'on_hold_at' => now(),
        ]);
    }

    /**
     * Resume the item.
     */
    public function resume(): void
    {
        $this->update([
            'status' => TaskChecklistItemStatus::InProgress,
            'resumed_at' => now(),
        ]);
    }

    /**
     * Reopen the item.
     */
    public function reopen(): void
    {
        $this->update([
            'status' => TaskChecklistItemStatus::InProgress,
            'reopened_at' => now(),
            'completed_by' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Reset item to todo.
     */
    public function resetToTodo(): void
    {
        $this->update([
            'status' => TaskChecklistItemStatus::Todo,
            'completed_by' => null,
            'completed_at' => null,
            'started_at' => null,
            'on_hold_at' => null,
            'resumed_at' => null,
            'reopened_at' => null,
            'last_worked_on_by' => null,
        ]);
    }
}
