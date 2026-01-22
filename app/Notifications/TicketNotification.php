<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    /**
     * Notification types.
     */
    public const TYPE_CREATED = 'ticket_created';

    public const TYPE_UPDATED = 'ticket_updated';

    public const TYPE_COMMENT = 'ticket_comment';

    public const TYPE_SLA_BREACH = 'ticket_sla';

    public const TYPE_ASSIGNED = 'ticket_assigned';

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Ticket $ticket,
        public string $type,
        public ?User $actor = null,
        public ?string $message = null,
        public array $metadata = []
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        // Check if user wants email for this notification type
        if ($notifiable->wantsEmailFor($this->type)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->getTitle();
        $actionUrl = config('app.url').'/tickets/'.$this->ticket->public_id;

        return (new MailMessage)
            ->subject($title)
            ->greeting('Hello '.$notifiable->name.'!')
            ->line($this->getMessage())
            ->line('**Ticket:** '.$this->ticket->ticket_number.' - '.$this->ticket->title)
            ->line('**Status:** '.$this->ticket->status->label())
            ->line('**Priority:** '.ucfirst($this->ticket->priority->value))
            ->action('View Ticket', $actionUrl)
            ->line('Thank you for using '.config('app.name').'!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'action_url' => '/tickets/'.$this->ticket->public_id,
            'action_label' => 'View Ticket',
            'metadata' => array_merge([
                'ticket_id' => $this->ticket->public_id,
                'ticket_number' => $this->ticket->ticket_number,
                'ticket_title' => $this->ticket->title,
                'status' => $this->ticket->status->label(),
                'priority' => ucfirst($this->ticket->priority->value),
            ], $this->metadata),
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
            'type' => self::class,
        ]);
    }

    /**
     * Get the notification title based on type.
     */
    protected function getTitle(): string
    {
        return match ($this->type) {
            self::TYPE_CREATED => 'New Ticket: '.$this->ticket->ticket_number,
            self::TYPE_UPDATED => 'Ticket Updated: '.$this->ticket->ticket_number,
            self::TYPE_COMMENT => 'New Comment on '.$this->ticket->ticket_number,
            self::TYPE_SLA_BREACH => '⚠️ SLA Breach: '.$this->ticket->ticket_number,
            self::TYPE_ASSIGNED => 'Ticket Assigned: '.$this->ticket->ticket_number,
            default => 'Ticket Notification',
        };
    }

    /**
     * Get the notification message.
     */
    protected function getMessage(): string
    {
        if ($this->message) {
            return $this->message;
        }

        $actorName = $this->actor?->name ?? 'Someone';

        return match ($this->type) {
            self::TYPE_CREATED => "{$actorName} created a new ticket: {$this->ticket->title}",
            self::TYPE_UPDATED => "{$actorName} updated ticket: {$this->ticket->title}",
            self::TYPE_COMMENT => "{$actorName} commented on ticket: {$this->ticket->title}",
            self::TYPE_SLA_BREACH => "SLA has been breached for ticket: {$this->ticket->title}",
            self::TYPE_ASSIGNED => "You have been assigned to ticket: {$this->ticket->title}",
            default => Str::limit($this->ticket->title, 100),
        };
    }
}
