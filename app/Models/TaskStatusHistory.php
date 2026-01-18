<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskStatusHistory extends Model
{
    /**
     * Disable updated_at timestamp.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'from_status',
        'to_status',
        'notes',
        'changed_by',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'task_status_history';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TaskStatusHistory $history): void {
            $history->created_at = now();
        });
    }

    /**
     * Get the task.
     *
     * @return BelongsTo<Task, TaskStatusHistory>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who made the change.
     *
     * @return BelongsTo<User, TaskStatusHistory>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Get the from status as enum.
     */
    public function getFromStatusEnumAttribute(): ?TaskStatus
    {
        return $this->from_status ? TaskStatus::from($this->from_status) : null;
    }

    /**
     * Get the to status as enum.
     */
    public function getToStatusEnumAttribute(): TaskStatus
    {
        return TaskStatus::from($this->to_status);
    }
}
