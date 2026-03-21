<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportSkill extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'description',
        'department',
        'is_active',
        'priority',
        'settings',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'settings' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportSkill $skill): void {
            if (empty($skill->public_id)) {
                $skill->public_id = (string) Str::ulid();
            }

            if (empty($skill->slug)) {
                $skill->slug = self::generateUniqueSlug($skill->name);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<User, SupportSkill>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<User>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'support_skill_user', 'support_skill_id', 'user_id')
            ->withPivot(['membership_role', 'is_primary', 'is_active', 'capacity', 'settings'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<InternalTeam>
     */
    public function internalTeams(): BelongsToMany
    {
        return $this->belongsToMany(InternalTeam::class, 'support_skill_internal_team', 'support_skill_id', 'internal_team_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<SupportConversation>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(SupportConversation::class, 'support_skill_id');
    }

    /**
     * @return HasMany<SupportSkillMembership>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(SupportSkillMembership::class, 'support_skill_id');
    }

    /**
     * @param  Builder<SupportSkill>  $query
     * @return Builder<SupportSkill>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'support-skill';
        }

        $slug = $base;
        $counter = 2;
        while (self::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
