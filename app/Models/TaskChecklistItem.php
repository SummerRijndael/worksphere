<?php

namespace App\Models;

use App\Enums\TaskChecklistItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TaskChecklistItem extends Model
{
    /** @use HasFactory<\Database\Factories\TaskChecklistItemFactory> */
    use HasFactory;

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
     * Reset item to todo.
     */
    public function resetToTodo(): void
    {
        $this->update([
            'status' => TaskChecklistItemStatus::Todo,
            'completed_by' => null,
            'completed_at' => null,
        ]);
    }
}
