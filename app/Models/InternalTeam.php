<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class InternalTeam extends Model implements HasMedia
{
    use Auditable, InteractsWithMedia;
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'department',
        'status',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (InternalTeam $team): void {
            if (empty($team->public_id)) {
                $team->public_id = (string) \Illuminate\Support\Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsToMany<User>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'internal_team_user', 'internal_team_id', 'user_id')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<SupportSkill>
     */
    public function supportSkills(): BelongsToMany
    {
        return $this->belongsToMany(SupportSkill::class, 'support_skill_internal_team', 'internal_team_id', 'support_skill_id')
            ->withTimestamps();
    }

    /**
     * Check if a user has a specific role in this team.
     */
    public function hasRole(User $user, string $role): bool
    {
        $member = $this->members()->where('user_id', $user->id)->first();
        if (!$member) return false;
        return $member->pivot->role === $role;
    }

    /**
     * @param  Builder<InternalTeam>  $query
     * @return Builder<InternalTeam>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
