<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Announcement extends Model
{
    protected $fillable = [
        'title',
        'message',
        'type',
        'action_text',
        'action_url',
        'is_dismissable',
        'is_active',
        'is_public',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected $casts = [
        'is_dismissable' => 'boolean',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Get the user who created the announcement.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get users who dismissed this announcement.
     */
    public function dismissedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_dismissals')
            ->withPivot('dismissed_at');
    }

    /**
     * Scope to filter active announcements.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter visible announcements (active and within schedule).
     */
    public function scopeVisible($query)
    {
        $now = now();

        return $query->active()
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $now);
            });
    }

    /**
     * Scope to exclude dismissed announcements for a user.
     */
    public function scopeNotDismissedBy($query, User $user)
    {
        return $query->whereDoesntHave('dismissedBy', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });
    }

    /**
     * Scope to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Check if announcement is currently visible.
     */
    public function isVisible(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->gt($now)) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->lte($now)) {
            return false;
        }

        return true;
    }

    /**
     * Check if this announcement has been dismissed by a user.
     */
    public function isDismissedBy(User $user): bool
    {
        return $this->dismissedBy()->where('user_id', $user->id)->exists();
    }

    /**
     * Dismiss this announcement for a user.
     */
    public function dismissFor(User $user): void
    {
        if ($this->is_dismissable && ! $this->isDismissedBy($user)) {
            $this->dismissedBy()->attach($user->id, ['dismissed_at' => now()]);
        }
    }

    /**
     * Get available announcement types.
     */
    public static function types(): array
    {
        return [
            'info' => [
                'label' => 'Information',
                'color' => 'blue',
                'icon' => 'info',
            ],
            'warning' => [
                'label' => 'Warning',
                'color' => 'amber',
                'icon' => 'alert-triangle',
            ],
            'danger' => [
                'label' => 'Danger',
                'color' => 'red',
                'icon' => 'alert-circle',
            ],
            'success' => [
                'label' => 'Success',
                'color' => 'green',
                'icon' => 'check-circle',
            ],
        ];
    }
}
