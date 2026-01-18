<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InvoiceSent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Create a new invoice with items.
     *
     * @param  array<string, mixed>  $data
     * @param  array<array<string, mixed>>  $items
     */
    public function createInvoice(
        Team $team,
        Client $client,
        User $creator,
        array $data,
        array $items,
        ?Project $project = null
    ): Invoice {
        return DB::transaction(function () use ($team, $client, $creator, $data, $items, $project) {
            $invoice = Invoice::create([
                'team_id' => $team->id,
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'issue_date' => $data['issue_date'] ?? now(),
                'due_date' => $data['due_date'] ?? now()->addDays(30),
                'tax_rate' => $data['tax_rate'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'created_by' => $creator->id,
            ]);

            // Create items
            $this->syncItems($invoice, $items);

            // Recalculate totals
            $invoice->refresh();
            $invoice->recalculateTotals();

            // Audit log
            $this->auditService->log(
                AuditAction::Created,
                AuditCategory::InvoiceManagement,
                $invoice,
                $creator,
                null,
                $invoice->toArray(),
                ['team_id' => $team->id, 'client_id' => $client->id]
            );

            return $invoice;
        });
    }

    /**
     * Update an invoice and its items.
     *
     * @param  array<string, mixed>  $data
     * @param  array<array<string, mixed>>|null  $items
     */
    public function updateInvoice(
        Invoice $invoice,
        array $data,
        ?array $items,
        User $updatedBy,
        ?Client $client = null,
        ?Project $project = null
    ): Invoice {
        return DB::transaction(function () use ($invoice, $data, $items, $updatedBy, $client, $project) {
            $oldValues = $invoice->toArray();

            // Update client if provided
            if ($client) {
                $invoice->client_id = $client->id;
            }

            // Update project if provided (can be null to unlink)
            if (array_key_exists('project_id', $data)) {
                $invoice->project_id = $project?->id;
            }

            // Update basic fields
            $invoice->fill([
                'issue_date' => $data['issue_date'] ?? $invoice->issue_date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'tax_rate' => $data['tax_rate'] ?? $invoice->tax_rate,
                'discount_amount' => $data['discount_amount'] ?? $invoice->discount_amount,
                'currency' => $data['currency'] ?? $invoice->currency,
                'notes' => $data['notes'] ?? $invoice->notes,
                'terms' => $data['terms'] ?? $invoice->terms,
            ]);

            $invoice->save();

            // Update items if provided
            if ($items !== null) {
                $this->syncItems($invoice, $items);
            }

            // Recalculate totals
            $invoice->refresh();
            $invoice->recalculateTotals();

            // Audit log
            $this->auditService->log(
                AuditAction::Updated,
                AuditCategory::InvoiceManagement,
                $invoice,
                $updatedBy,
                $oldValues,
                $invoice->toArray()
            );

            return $invoice;
        });
    }

    /**
     * Sync invoice items (create, update, delete).
     *
     * @param  array<array<string, mixed>>  $items
     */
    protected function syncItems(Invoice $invoice, array $items): void
    {
        $existingItemIds = $invoice->items()->pluck('id')->toArray();
        $updatedItemIds = [];

        foreach ($items as $index => $itemData) {
            if (! empty($itemData['id']) && in_array($itemData['id'], $existingItemIds)) {
                // Update existing item
                $item = InvoiceItem::find($itemData['id']);
                $item->update([
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'sort_order' => $index,
                ]);
                $updatedItemIds[] = $item->id;
            } else {
                // Create new item
                $newItem = InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $itemData['description'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'sort_order' => $index,
                ]);
                $updatedItemIds[] = $newItem->id;
            }
        }

        // Delete items that were not in the update
        $toDelete = array_diff($existingItemIds, $updatedItemIds);
        if (! empty($toDelete)) {
            InvoiceItem::whereIn('id', $toDelete)->delete();
        }
    }

    /**
     * Send invoice to client.
     */
    public function sendInvoice(Invoice $invoice, User $sentBy, ?string $email = null): bool
    {
        if (! $invoice->status->canSend()) {
            return false;
        }

        // Generate PDF if not exists
        $pdfPath = $this->generatePdf($invoice);

        // Get target email
        $targetEmail = $email ?? $invoice->client->email;

        // Mark as sent
        $invoice->markAsSent($targetEmail);

        // Send notification
        $invoice->client->notify(new InvoiceSent($invoice, $pdfPath));

        // Audit log
        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::InvoiceManagement,
            $invoice,
            $sentBy,
            ['status' => InvoiceStatus::Draft->value],
            ['status' => InvoiceStatus::Sent->value],
            ['action' => 'sent', 'sent_to' => $targetEmail]
        );

        return true;
    }

    /**
     * Record a payment for the invoice.
     */
    public function recordPayment(Invoice $invoice, User $recordedBy): bool
    {
        if (! $invoice->status->canRecordPayment()) {
            return false;
        }

        $oldStatus = $invoice->status->value;
        $invoice->markAsPaid();

        // Audit log
        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::InvoiceManagement,
            $invoice,
            $recordedBy,
            ['status' => $oldStatus, 'paid_at' => null],
            ['status' => InvoiceStatus::Paid->value, 'paid_at' => $invoice->paid_at],
            ['action' => 'payment_recorded']
        );

        return true;
    }

    /**
     * Mark invoice as viewed (when client opens it).
     */
    public function markAsViewed(Invoice $invoice): bool
    {
        return $invoice->markAsViewed();
    }

    /**
     * Cancel an invoice.
     */
    public function cancelInvoice(Invoice $invoice, User $cancelledBy): bool
    {
        $oldStatus = $invoice->status->value;

        if (! $invoice->cancel()) {
            return false;
        }

        // Audit log
        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::InvoiceManagement,
            $invoice,
            $cancelledBy,
            ['status' => $oldStatus],
            ['status' => InvoiceStatus::Cancelled->value],
            ['action' => 'cancelled']
        );

        return true;
    }

    /**
     * Delete an invoice.
     */
    public function deleteInvoice(Invoice $invoice, User $deletedBy): bool
    {
        $this->auditService->log(
            AuditAction::Deleted,
            AuditCategory::InvoiceManagement,
            $invoice,
            $deletedBy,
            $invoice->toArray(),
            null
        );

        return $invoice->delete();
    }

    /**
     * Generate PDF for invoice.
     */
    public function generatePdf(Invoice $invoice): string
    {
        $invoice->load(['client', 'project', 'team', 'items', 'creator']);

        $pdf = Pdf::loadView('pdf.invoice-pdf', [
            'invoice' => $invoice,
        ]);

        $filename = "invoices/{$invoice->public_id}.pdf";
        Storage::disk('local')->put($filename, $pdf->output());

        $invoice->update(['pdf_path' => $filename]);

        return $filename;
    }

    /**
     * Get PDF path, generating if needed.
     */
    public function getPdfPath(Invoice $invoice): string
    {
        if ($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        return $this->generatePdf($invoice);
    }

    /**
     * Check and mark overdue invoices.
     */
    public function checkOverdueInvoices(): int
    {
        $count = 0;

        Invoice::where('due_date', '<', now()->startOfDay())
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Viewed->value])
            ->chunkById(100, function ($invoices) use (&$count) {
                foreach ($invoices as $invoice) {
                    $invoice->markAsOverdue();
                    $count++;
                }
            });

        return $count;
    }
}
