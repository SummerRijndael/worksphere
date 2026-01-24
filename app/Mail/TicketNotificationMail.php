<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Notification types.
     */
    public const TYPE_CREATED = 'created';

    public const TYPE_UPDATED = 'updated';

    public const TYPE_COMMENT = 'comment';

    public const TYPE_SLA_BREACH = 'sla_breach';

    public const TYPE_ASSIGNED = 'assigned';

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Ticket $ticket,
        public User $recipient,
        public string $type,
        public ?User $actor = null,
        public ?string $customMessage = null,
        public array $meta = []
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->recipient->email],
            subject: $this->getSubject(),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-notification',
            with: [
                'ticket' => $this->ticket,
                'recipient' => $this->recipient,
                'type' => $this->type,
                'actor' => $this->actor,
                'customMessage' => $this->customMessage,
                'metadata' => $this->meta,
                'title' => $this->getTitle(),
                'notificationMessage' => $this->getMessage(),
                'actionUrl' => $this->getActionUrl(),
                'actionText' => 'View Ticket',
                'unsubscribeUrl' => $this->getUnsubscribeUrl(),
                'appName' => config('app.name'),
                'appLogo' => $this->getAppLogo(),
            ],
        );
    }

    /**
     * Get email subject.
     */
    protected function getSubject(): string
    {
        $ticketNumber = $this->ticket->ticket_number;

        return match ($this->type) {
            self::TYPE_CREATED => "[{$ticketNumber}] New Ticket Created",
            self::TYPE_UPDATED => "[{$ticketNumber}] Ticket Updated",
            self::TYPE_COMMENT => "[{$ticketNumber}] New Comment",
            self::TYPE_SLA_BREACH => "⚠️ [{$ticketNumber}] SLA Breach Alert",
            self::TYPE_ASSIGNED => "[{$ticketNumber}] Ticket Assigned to You",
            default => "[{$ticketNumber}] Ticket Notification",
        };
    }

    /**
     * Get notification title.
     */
    protected function getTitle(): string
    {
        return match ($this->type) {
            self::TYPE_CREATED => 'New Ticket Created',
            self::TYPE_UPDATED => 'Ticket Updated',
            self::TYPE_COMMENT => 'New Comment Added',
            self::TYPE_SLA_BREACH => 'SLA Breach Alert',
            self::TYPE_ASSIGNED => 'Ticket Assigned',
            default => 'Ticket Notification',
        };
    }

    /**
     * Get notification message.
     */
    protected function getMessage(): string
    {
        if ($this->customMessage) {
            return $this->customMessage;
        }

        $actorName = $this->actor?->name ?? 'Someone';

        return match ($this->type) {
            self::TYPE_CREATED => "{$actorName} created a new ticket that may require your attention.",
            self::TYPE_UPDATED => "{$actorName} updated this ticket.",
            self::TYPE_COMMENT => "{$actorName} added a new comment to this ticket.",
            self::TYPE_SLA_BREACH => 'The SLA threshold has been exceeded for this ticket. Immediate attention is required.',
            self::TYPE_ASSIGNED => 'You have been assigned to handle this ticket.',
            default => 'There has been activity on this ticket.',
        };
    }

    /**
     * Get action URL.
     */
    protected function getActionUrl(): string
    {
        return config('app.url').'/tickets/'.$this->ticket->public_id;
    }

    /**
     * Get unsubscribe URL.
     */
    protected function getUnsubscribeUrl(): string
    {
        return config('app.url').'/settings/notifications';
    }

    /**
     * Get app logo URL.
     */
    protected function getAppLogo(): ?string
    {
        // Try to get from settings, fall back to default
        $logo = app(\App\Services\AppSettingsService::class)->get('app.logo');

        if ($logo) {
            return config('app.url').'/storage/'.$logo;
        }

        return null;
    }
}
