<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TeamRole extends Model
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
        'slug',
        'description',
        'color',
        'level',
        'is_default',
        'is_system',
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

        static::creating(function (TeamRole $role): void {
            if (empty($role->public_id)) {
                $role->public_id = (string) Str::uuid();
            }

            if (empty($role->slug)) {
                $role->slug = self::generateSlug($role->name, $role->team_id);
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
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'level' => 'integer',
        ];
    }

    /**
     * Generate a unique slug from name within the team.
     */
    public static function generateSlug(string $name, int $teamId): string
    {
        $baseSlug = Str::slug($name);

        if (strlen($baseSlug) < 2) {
            $baseSlug = 'role';
        }

        $baseSlug = Str::limit($baseSlug, 50, '');

        $slug = $baseSlug;
        $counter = 1;

        while (self::where('slug', $slug)->where('team_id', $teamId)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get the team that owns this role.
     *
     * @return BelongsTo<Team, TeamRole>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created this role.
     *
     * @return BelongsTo<User, TeamRole>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the permissions for this role.
     *
     * @return HasMany<TeamRolePermission>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(TeamRolePermission::class);
    }

    /**
     * Get the users with this role.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<User>
     */
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user', 'team_role_id', 'user_id')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Get the count of members with this role.
     */
    public function getMemberCountAttribute(): int
    {
        return $this->users()->count();
    }

    /**
     * Get permission names as an array.
     *
     * @return array<string>
     */
    public function getPermissionNamesAttribute(): array
    {
        return $this->permissions->pluck('permission')->toArray();
    }

    /**
     * Check if this role has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()->where('permission', $permission)->exists();
    }

    /**
     * Check if this role has any of the given permissions.
     *
     * @param  array<string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return $this->permissions()->whereIn('permission', $permissions)->exists();
    }

    /**
     * Check if this role has all of the given permissions.
     *
     * @param  array<string>  $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        $count = $this->permissions()->whereIn('permission', $permissions)->count();

        return $count === count($permissions);
    }

    /**
     * Sync permissions for this role.
     *
     * @param  array<string>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        $this->permissions()->delete();

        foreach ($permissions as $permission) {
            $this->permissions()->create(['permission' => $permission]);
        }
    }

    /**
     * Grant a permission to this role.
     */
    public function grantPermission(string $permission): void
    {
        if (! $this->hasPermission($permission)) {
            $this->permissions()->create(['permission' => $permission]);
        }
    }

    /**
     * Revoke a permission from this role.
     */
    public function revokePermission(string $permission): void
    {
        $this->permissions()->where('permission', $permission)->delete();
    }

    /**
     * Check if the role can be deleted.
     */
    public function canBeDeleted(): bool
    {
        if ($this->is_system) {
            return false;
        }

        return ! $this->users()->exists();
    }

    /**
     * Check if role has higher level than another.
     */
    public function hasHigherLevelThan(TeamRole $role): bool
    {
        return $this->level > $role->level;
    }

    /**
     * Scope: Only roles for a specific team.
     *
     * @param  Builder<TeamRole>  $query
     * @return Builder<TeamRole>
     */
    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    /**
     * Scope: Only default roles.
     *
     * @param  Builder<TeamRole>  $query
     * @return Builder<TeamRole>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope: Only non-system roles.
     *
     * @param  Builder<TeamRole>  $query
     * @return Builder<TeamRole>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope: Order by level descending.
     *
     * @param  Builder<TeamRole>  $query
     * @return Builder<TeamRole>
     */
    public function scopeOrderByLevel(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderBy('level', $direction);
    }
}
