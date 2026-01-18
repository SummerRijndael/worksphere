<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskQaCheckResult extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'task_qa_review_id',
        'qa_check_item_id',
        'passed',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
        ];
    }

    /**
     * Get the review.
     *
     * @return BelongsTo<TaskQaReview, TaskQaCheckResult>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(TaskQaReview::class, 'task_qa_review_id');
    }

    /**
     * Get the check item.
     *
     * @return BelongsTo<QaCheckItem, TaskQaCheckResult>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(QaCheckItem::class, 'qa_check_item_id');
    }
}
