<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class RoleTwoFactorEnforcement extends Model
{
    protected $table = 'role_two_factor_enforcement';

    protected $fillable = [
        'role_id',
        'allowed_methods',
        'is_active',
        'enforced_by',
        'enforced_at',
    ];

    /**
     * @return array{allowed_methods: 'array', is_active: 'boolean', enforced_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'allowed_methods' => 'array',
            'is_active' => 'boolean',
            'enforced_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function enforcedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enforced_by');
    }
}
