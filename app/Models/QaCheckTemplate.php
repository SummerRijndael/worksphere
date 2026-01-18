<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class QaCheckTemplate extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'description',
        'is_active',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'team_id',
        'created_by',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (QaCheckTemplate $template): void {
            if (empty($template->public_id)) {
                $template->public_id = (string) Str::uuid();
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
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the team.
     *
     * @return BelongsTo<Team, QaCheckTemplate>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the creator.
     *
     * @return BelongsTo<User, QaCheckTemplate>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the check items.
     *
     * @return HasMany<QaCheckItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QaCheckItem::class)->orderBy('order');
    }

    /**
     * Get the reviews using this template.
     *
     * @return HasMany<TaskQaReview>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(TaskQaReview::class);
    }

    /**
     * Scope: Active templates only.
     *
     * @param  Builder<QaCheckTemplate>  $query
     * @return Builder<QaCheckTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: For a specific team.
     *
     * @param  Builder<QaCheckTemplate>  $query
     * @return Builder<QaCheckTemplate>
     */
    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }
}
