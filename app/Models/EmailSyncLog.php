<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'action',
        'folder',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    // ==================
    // Constants
    // ==================

    public const ACTION_SEED_STARTED = 'seed_started';

    public const ACTION_SEED_COMPLETED = 'seed_completed';

    public const ACTION_CHUNK_STARTED = 'chunk_started';

    public const ACTION_CHUNK_COMPLETED = 'chunk_completed';

    public const ACTION_SYNC_COMPLETED = 'sync_completed';

    public const ACTION_INCREMENTAL_FETCH = 'incremental_fetch';

    public const ACTION_ERROR = 'error';

    // ==================
    // Relationships
    // ==================

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    // ==================
    // Scopes
    // ==================

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('email_account_id', $accountId);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeErrors($query)
    {
        return $query->where('action', self::ACTION_ERROR);
    }

    // ==================
    // Factory Methods
    // ==================

    public static function logSeedStarted(int $accountId, array $folders): self
    {
        return self::create([
            'email_account_id' => $accountId,
            'action' => self::ACTION_SEED_STARTED,
            'details' => ['folders' => $folders],
        ]);
    }

    public static function logChunkCompleted(
        int $accountId,
        string $folder,
        int $offset,
        int $fetched,
        int $durationMs
    ): self {
        return self::create([
            'email_account_id' => $accountId,
            'action' => self::ACTION_CHUNK_COMPLETED,
            'folder' => $folder,
            'details' => [
                'offset' => $offset,
                'fetched_count' => $fetched,
                'duration_ms' => $durationMs,
            ],
        ]);
    }

    public static function logError(int $accountId, string $error, ?string $folder = null): self
    {
        return self::create([
            'email_account_id' => $accountId,
            'action' => self::ACTION_ERROR,
            'folder' => $folder,
            'details' => ['error' => $error],
        ]);
    }
}
