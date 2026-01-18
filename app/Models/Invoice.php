<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'invoices';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'team_id',
        'client_id',
        'project_id',
        'invoice_number',
        'status',
        'issue_date',
        'due_date',
        'paid_at',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'total',
        'currency',
        'notes',
        'terms',
        'sent_at',
        'sent_to_email',
        'viewed_at',
        'pdf_path',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for arrays.
     */
    protected $hidden = [
        'id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Invoice $invoice): void {
            if (empty($invoice->public_id)) {
                $invoice->public_id = (string) Str::uuid();
            }

            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::generateInvoiceNumber($invoice->team_id);
            }

            if (empty($invoice->status)) {
                $invoice->status = InvoiceStatus::Draft;
            }

            if (empty($invoice->currency)) {
                $invoice->currency = 'USD';
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
     * Generate a unique invoice number.
     */
    public static function generateInvoiceNumber(int $teamId): string
    {
        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');

        // Count invoices for this team this month
        $count = self::where('team_id', $teamId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->count() + 1;

        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $count);
    }

    /**
     * Get the team that owns this invoice.
     *
     * @return BelongsTo<Team, Invoice>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the client for this invoice.
     *
     * @return BelongsTo<Client, Invoice>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the project associated with this invoice.
     *
     * @return BelongsTo<Project, Invoice>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user who created this invoice.
     *
     * @return BelongsTo<User, Invoice>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the invoice items.
     *
     * @return HasMany<InvoiceItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * Calculate and update totals based on items.
     */
    public function calculateTotals(): self
    {
        $subtotal = $this->items->sum('total');
        $taxAmount = round($subtotal * ((float) $this->tax_rate / 100), 2);
        $total = $subtotal + $taxAmount - (float) $this->discount_amount;

        $this->subtotal = $subtotal;
        $this->tax_amount = $taxAmount;
        $this->total = max(0, $total);

        return $this;
    }

    /**
     * Recalculate and save totals.
     */
    public function recalculateTotals(): bool
    {
        $this->calculateTotals();

        return $this->save();
    }

    /**
     * Mark the invoice as sent.
     */
    public function markAsSent(?string $email = null): bool
    {
        if (! $this->status->canSend()) {
            return false;
        }

        $this->status = InvoiceStatus::Sent;
        $this->sent_at = now();
        $this->sent_to_email = $email ?? $this->client->email;

        return $this->save();
    }

    /**
     * Mark the invoice as viewed.
     */
    public function markAsViewed(): bool
    {
        if ($this->status !== InvoiceStatus::Sent) {
            return false;
        }

        $this->status = InvoiceStatus::Viewed;
        $this->viewed_at = now();

        return $this->save();
    }

    /**
     * Mark the invoice as paid.
     */
    public function markAsPaid(): bool
    {
        if (! $this->status->canRecordPayment()) {
            return false;
        }

        $this->status = InvoiceStatus::Paid;
        $this->paid_at = now();

        return $this->save();
    }

    /**
     * Mark the invoice as overdue.
     */
    public function markAsOverdue(): bool
    {
        if (! in_array($this->status, [InvoiceStatus::Sent, InvoiceStatus::Viewed])) {
            return false;
        }

        $this->status = InvoiceStatus::Overdue;

        return $this->save();
    }

    /**
     * Cancel the invoice.
     */
    public function cancel(): bool
    {
        if ($this->status === InvoiceStatus::Paid) {
            return false;
        }

        $this->status = InvoiceStatus::Cancelled;

        return $this->save();
    }

    /**
     * Check if the invoice is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === InvoiceStatus::Paid || $this->status === InvoiceStatus::Cancelled) {
            return false;
        }

        return $this->due_date && $this->due_date->isPast();
    }

    /**
     * Get days until due or days overdue.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }

    /**
     * Check if the invoice can be edited.
     */
    public function getCanEditAttribute(): bool
    {
        return $this->status->canEdit();
    }

    /**
     * Check if the invoice can be sent.
     */
    public function getCanSendAttribute(): bool
    {
        return $this->status->canSend() && $this->items()->count() > 0;
    }

    /**
     * Check if a payment can be recorded.
     */
    public function getCanRecordPaymentAttribute(): bool
    {
        return $this->status->canRecordPayment();
    }

    /**
     * Scope: Invoices for a specific client.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeForClient(Builder $query, Client $client): Builder
    {
        return $query->where('client_id', $client->id);
    }

    /**
     * Scope: Invoices for a specific team.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeForTeam(Builder $query, Team $team): Builder
    {
        return $query->where('team_id', $team->id);
    }

    /**
     * Scope: Pending invoices (sent, viewed, or overdue).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Sent->value,
            InvoiceStatus::Viewed->value,
            InvoiceStatus::Overdue->value,
        ]);
    }

    /**
     * Scope: Paid invoices.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Paid->value);
    }

    /**
     * Scope: Draft invoices.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Draft->value);
    }

    /**
     * Scope: Overdue invoices (past due date and not paid).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', now()->startOfDay())
            ->whereNotIn('status', [
                InvoiceStatus::Paid->value,
                InvoiceStatus::Cancelled->value,
                InvoiceStatus::Draft->value,
            ]);
    }

    /**
     * Scope: Filter by status.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeWithStatus(Builder $query, InvoiceStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
