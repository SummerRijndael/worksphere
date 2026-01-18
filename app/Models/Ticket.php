<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Ticket extends Model implements HasMedia
{
    use Auditable;
    use HasFactory;
    use InteractsWithMedia;
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'description',
        'status',
        'priority',
        'type',
        'tags',
        'reporter_id',
        'assigned_to',
        'team_id',
        'sla_response_hours',
        'sla_resolution_hours',
        'first_response_at',
        'sla_breached',
        'due_date',
        'deadline_reminded_at',
        'resolved_at',
        'closed_at',
        'parent_id',
        'archived_at',
        'archive_reason',
        'archive_reason',
        'ticket_number', // Added
        'guest_name',
        'guest_email',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'reporter_id',
        'assigned_to',
        'team_id',
    ];

    /**
     * The attributes that should be appended to arrays.
     *
     * @var list<string>
     */
    protected $appends = [
        'comment_count',
        'is_overdue',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Ticket $ticket): void {
            if (empty($ticket->public_id)) {
                $ticket->public_id = (string) Str::uuid();
            }

            if (empty($ticket->slug)) {
                $ticket->slug = self::generateSlug($ticket->title);
            }

            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = self::generateTicketNumber($ticket);
            }
        });

        static::updating(function (Ticket $ticket): void {
            if ($ticket->isDirty('title')) {
                $ticket->slug = self::generateSlug($ticket->title, $ticket->id);
            }
        });
    }

    /**
     * Generate a unique slug from title.
     */
    public static function generateSlug(string $title, ?int $excludeId = null): string
    {
        $baseSlug = Str::slug($title);

        if (strlen($baseSlug) < 2) {
            $baseSlug = 'ticket';
        }

        $baseSlug = Str::limit($baseSlug, 80, '');

        $slug = $baseSlug;
        $counter = 1;

        $query = self::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
            $query = self::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }

    /**
     * Generate a unique ticket number based on type.
     */
    public static function generateTicketNumber(Ticket $ticket): string
    {
        $prefix = $ticket->type->prefix();

        // Efficiently find the highest ticket number using string ordering (works because of zero-padding)
        $latestTicket = self::query()
            ->where('ticket_number', 'like', "{$prefix}-%")
            ->orderBy('ticket_number', 'desc')
            ->first();

        $maxNumber = 0;
        if ($latestTicket) {
            $parts = explode('-', $latestTicket->ticket_number);
            $val = end($parts);
            $maxNumber = is_numeric($val) ? (int) $val : 0;
        }

        $nextNumber = ($maxNumber ?? 0) + 1;

        return $prefix.'-'.str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Register the media collections.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments');
    }

    /**
     * Register media conversions.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(200)
            ->height(200)
            ->sharpen(10)
            ->nonQueued();
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
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'type' => TicketType::class,
            'tags' => 'array',
            'sla_breached' => 'boolean',
            'first_response_at' => 'datetime',
            'due_date' => 'datetime',
            'deadline_reminded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Get the ticket reporter.
     *
     * @return BelongsTo<User, Ticket>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * Get the ticket assignee.
     *
     * @return BelongsTo<User, Ticket>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the ticket team.
     *
     * @return BelongsTo<Team, Ticket>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the ticket comments.
     *
     * @return HasMany<TicketComment>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get the ticket internal notes.
     *
     * @return HasMany<TicketInternalNote>
     */
    public function internalNotes(): HasMany
    {
        return $this->hasMany(TicketInternalNote::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get users following this ticket.
     *
     * @return BelongsToMany<User>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_followers')
            ->withPivot('created_at');
    }

    /**
     * Get the comment count attribute.
     */
    public function getCommentCountAttribute(): int
    {
        return $this->comments()->count();
    }

    /**
     * Check if ticket is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (! $this->due_date) {
            return false;
        }

        if ($this->status->isTerminal()) {
            return false;
        }

        return $this->due_date->isPast();
    }

    /**
     * Check if response SLA is breached.
     */
    public function isResponseSlaBreached(): bool
    {
        if (! $this->sla_response_hours) {
            return false;
        }

        if ($this->first_response_at) {
            $responseTime = $this->created_at->diffInHours($this->first_response_at);

            return $responseTime > $this->sla_response_hours;
        }

        return $this->created_at->addHours($this->sla_response_hours)->isPast();
    }

    /**
     * Check if resolution SLA is breached.
     */
    public function isResolutionSlaBreached(): bool
    {
        if (! $this->sla_resolution_hours) {
            return false;
        }

        if ($this->resolved_at) {
            $resolutionTime = $this->created_at->diffInHours($this->resolved_at);

            return $resolutionTime > $this->sla_resolution_hours;
        }

        if ($this->status->isTerminal()) {
            return false;
        }

        return $this->created_at->addHours($this->sla_resolution_hours)->isPast();
    }

    /**
     * Check if user is following this ticket.
     */
    public function isFollowedBy(User $user): bool
    {
        return $this->followers()->where('user_id', $user->id)->exists();
    }

    /**
     * Scope: Only open tickets.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TicketStatus::Open);
    }

    /**
     * Scope: Only unassigned tickets.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }

    /**
     * Scope: Tickets for a specific user (reporter or assignee).
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            $q->where('reporter_id', $user->id)
                ->orWhere('assigned_to', $user->id);
        });
    }

    /**
     * Scope: Overdue tickets.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed]);
    }

    /**
     * Scope: SLA breached tickets.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeSlaBreached(Builder $query): Builder
    {
        return $query->where('sla_breached', true);
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => strip_tags($this->description ?? ''),
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'type' => $this->type->value,
        ];
    }

    /**
     * Get parent ticket.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'parent_id');
    }

    /**
     * Get child tickets.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Ticket::class, 'parent_id');
    }

    /**
     * Scope: Only active (non-archived) tickets.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope: Only archived tickets.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Check if ticket is archived.
     */
    public function getIsArchivedAttribute(): bool
    {
        return ! is_null($this->archived_at);
    }

    /**
     * Check if ticket is locked (Archived or Child).
     */
    public function getIsLockedAttribute(): bool
    {
        return $this->is_archived || ! is_null($this->parent_id);
    }
}
