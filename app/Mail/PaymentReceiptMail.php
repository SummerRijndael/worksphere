<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Invoice $invoice,
        public string $pdfPath,
        public string $paymentDate,
        public ?string $disk = null
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $teamName = $this->invoice->team->name ?? config('app.name');

        return new Envelope(
            subject: "Payment Receipt for Invoice #{$this->invoice->invoice_number} - {$teamName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoices.payment-receipt',
            with: [
                'invoice' => $this->invoice,
                'paymentDate' => $this->paymentDate,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $disk = $this->disk ?: config('media-library.disk_name');

        return [
            Attachment::fromStorageDisk($disk, $this->pdfPath)
                ->as("Receipt-{$this->invoice->invoice_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
