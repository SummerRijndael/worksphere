<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserRoleChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'from_role',
        'to_role',
        'reason',
        'changed_by',
        'password_verified_at',
        'metadata',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (UserRoleChange $change): void {
            if (empty($change->public_id)) {
                $change->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array{password_verified_at: 'datetime', metadata: 'array', created_at: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'password_verified_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
