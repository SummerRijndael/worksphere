<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaskQaReview extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'task_id',
        'qa_check_template_id',
        'reviewer_id',
        'status',
        'notes',
        'reviewed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'task_id',
        'qa_check_template_id',
        'reviewer_id',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TaskQaReview $review): void {
            if (empty($review->public_id)) {
                $review->public_id = (string) Str::uuid();
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
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Get the task.
     *
     * @return BelongsTo<Task, TaskQaReview>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the template used.
     *
     * @return BelongsTo<QaCheckTemplate, TaskQaReview>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QaCheckTemplate::class, 'qa_check_template_id');
    }

    /**
     * Get the reviewer.
     *
     * @return BelongsTo<User, TaskQaReview>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Get the check results.
     *
     * @return HasMany<TaskQaCheckResult>
     */
    public function results(): HasMany
    {
        return $this->hasMany(TaskQaCheckResult::class);
    }

    /**
     * Check if review is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if review passed.
     */
    public function passed(): bool
    {
        return $this->status === 'passed';
    }

    /**
     * Check if review failed.
     */
    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Complete the review.
     *
     * @param  array<int, array{qa_check_item_id: int, passed: bool, notes?: string}>  $results
     */
    public function complete(array $results, ?string $notes = null): void
    {
        $allPassed = true;

        foreach ($results as $result) {
            $checkResult = $this->results()->updateOrCreate(
                ['qa_check_item_id' => $result['qa_check_item_id']],
                [
                    'passed' => $result['passed'],
                    'notes' => $result['notes'] ?? null,
                ]
            );

            // Check if required item failed
            $item = QaCheckItem::find($result['qa_check_item_id']);
            if ($item && $item->is_required && ! $result['passed']) {
                $allPassed = false;
            }
        }

        $this->update([
            'status' => $allPassed ? 'passed' : 'failed',
            'notes' => $notes,
            'reviewed_at' => now(),
        ]);
    }
}
