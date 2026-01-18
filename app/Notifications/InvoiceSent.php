<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class InvoiceSent extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Invoice $invoice,
        public string $pdfPath
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice;
        $teamName = $invoice->team->name ?? config('app.name');

        $message = (new MailMessage)
            ->subject("Invoice #{$invoice->invoice_number} from {$teamName}")
            ->greeting("Hello {$invoice->client->name},")
            ->line("Please find attached your invoice #{$invoice->invoice_number}.")
            ->line('**Invoice Details:**')
            ->line("- Invoice Number: {$invoice->invoice_number}")
            ->line('- Issue Date: '.$invoice->issue_date?->format('M d, Y'))
            ->line('- Due Date: '.$invoice->due_date?->format('M d, Y'))
            ->line('- Total Amount: '.$this->formatCurrency($invoice->total, $invoice->currency));

        // Add view online link if we have a public portal URL
        $portalUrl = config('app.url').'/portal/invoices/'.$invoice->public_id;
        $message->action('View Invoice Online', $portalUrl);

        $message->line('Thank you for your business!');

        // Attach PDF
        if (Storage::disk('local')->exists($this->pdfPath)) {
            $message->attach(Storage::disk('local')->path($this->pdfPath), [
                'as' => "Invoice-{$invoice->invoice_number}.pdf",
                'mime' => 'application/pdf',
            ]);
        }

        return $message;
    }

    /**
     * Format currency amount.
     */
    protected function formatCurrency(float $amount, string $currency): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
        ];

        $symbol = $symbols[$currency] ?? $currency.' ';

        return $symbol.number_format($amount, 2);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->public_id,
            'invoice_number' => $this->invoice->invoice_number,
            'total' => $this->invoice->total,
            'currency' => $this->invoice->currency,
        ];
    }
}
