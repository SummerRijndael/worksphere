<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportSkillMembership extends Model
{
    use HasFactory;

    protected $table = 'support_skill_user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'support_skill_id',
        'user_id',
        'membership_role',
        'is_primary',
        'is_active',
        'capacity',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'capacity' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SupportSkill, SupportSkillMembership>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(SupportSkill::class, 'support_skill_id');
    }

    /**
     * @return BelongsTo<User, SupportSkillMembership>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

