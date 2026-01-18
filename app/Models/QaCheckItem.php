<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QaCheckItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'qa_check_template_id',
        'label',
        'description',
        'order',
        'is_required',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    /**
     * Get the template.
     *
     * @return BelongsTo<QaCheckTemplate, QaCheckItem>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QaCheckTemplate::class, 'qa_check_template_id');
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
}
