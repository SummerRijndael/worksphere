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
        protected AuditService $auditService,
        protected MediaService $mediaService
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
                'address_to' => $data['address_to'] ?? null,
                'pdf_password' => $data['pdf_password'] ?? null,
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
                'address_to' => $data['address_to'] ?? $invoice->address_to,
                'pdf_password' => $data['pdf_password'] ?? $invoice->pdf_password,
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
        $invoice->update(['sent_by' => $sentBy->id]);

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
    public function recordPayment(
        Invoice $invoice,
        User $recordedBy,
        string $date,
        ?string $note = null,
        ?\Illuminate\Http\UploadedFile $proof = null,
        bool $sendReceipt = false,
        ?float $amount = null
    ): Invoice {
        if (! $invoice->status->canRecordPayment()) {
            throw new \Exception('Cannot record payment for this invoice.');
        }

        $proofMedia = null;
        if ($proof) {
            $proofMedia = $this->mediaService->attach(
                model: $invoice,
                file: $proof,
                collection: 'payment_proofs'
            );
        }

        if ($note) {
            $paymentNote = 'Payment Note: '.$note;
            $invoice->notes = $invoice->notes
                ? $invoice->notes."\n\n".$paymentNote
                : $paymentNote;
        }

        $invoice->markAsPaid($recordedBy, $amount);

        if ($sendReceipt) {
            $this->sendReceipt($invoice, $date, $note);
        }

        $this->auditService->log(
            action: AuditAction::Updated,
            category: AuditCategory::InvoiceManagement,
            auditable: $invoice,
            context: [
                'action' => 'payment_recorded',
                'team_id' => $invoice->team_id,
                'paid_at' => $date,
                'paid_amount' => $amount ?? $invoice->total,
                'note' => $note,
                'has_proof' => (bool) $proofMedia,
                'recorded_by' => $recordedBy->id,
                'receipt_sent' => $sendReceipt,
            ]
        );

        return $invoice;
    }

    /**
     * Generate and send receipt to client.
     */
    protected function sendReceipt(Invoice $invoice, string $date, ?string $note = null): bool
    {
        $invoice->load(['client', 'team', 'items']);

        // Generate Receipt PDF
        $pdf = Pdf::loadView('pdf.payment-receipt-pdf', [
            'invoice' => $invoice,
            'paymentDate' => $date,
            'note' => $note,
        ]);

        // Apply password if set
        if ($invoice->pdf_password) {
            $dompdf = $pdf->getDomPDF();
            $dompdf->render();
            $dompdf->getCanvas()->get_cpdf()->setEncryption($invoice->pdf_password, config('app.key'));
        }

        $filename = "{$invoice->invoice_number}-receipt.pdf";
        $media = $invoice->addMediaFromStream($pdf->output())
            ->usingFileName($filename)
            ->toMediaCollection('receipts');

        // Send Receipt Email
        \Illuminate\Support\Facades\Mail::to($invoice->client->email)
            ->send(new \App\Mail\PaymentReceiptMail(
                invoice: $invoice,
                pdfPath: $media->getPath(),
                paymentDate: $date,
                disk: $media->disk
            ));

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
    public function cancelInvoice(Invoice $invoice, User $cancelledBy, ?string $reason = null): bool
    {
        $oldStatus = $invoice->status->value;

        if (! $invoice->cancel()) {
            return false;
        }

        if ($reason) {
            $invoice->update(['notes' => $invoice->notes ? $invoice->notes."\n\nCancellation Reason: ".$reason : 'Cancellation Reason: '.$reason]);
        }

        // Audit log
        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::InvoiceManagement,
            $invoice,
            $cancelledBy,
            ['status' => $oldStatus],
            ['status' => InvoiceStatus::Cancelled->value],
            ['action' => 'cancelled', 'reason' => $reason]
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

        // Apply password if set
        if ($invoice->pdf_password) {
            $dompdf = $pdf->getDomPDF();
            $dompdf->render();
            $dompdf->getCanvas()->get_cpdf()->setEncryption($invoice->pdf_password, config('app.key'));
        }

        $filename = "{$invoice->invoice_number}.pdf";
        $media = $invoice->addMediaFromStream($pdf->output())
            ->usingFileName($filename)
            ->toMediaCollection('invoices');

        return $media->getPath();
    }

    /**
     * Get PDF path, generating if needed.
     */
    public function getPdfPath(Invoice $invoice): string
    {
        $media = $invoice->getFirstMedia('invoices');

        if ($media && Storage::disk($media->disk)->exists($media->getPath())) {
            return $media->getPath();
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
