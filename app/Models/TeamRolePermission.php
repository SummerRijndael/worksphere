<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamRolePermission extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'team_role_id',
        'permission',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'team_role_id',
    ];

    /**
     * Get the role that owns this permission.
     *
     * @return BelongsTo<TeamRole, TeamRolePermission>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(TeamRole::class, 'team_role_id');
    }
}
