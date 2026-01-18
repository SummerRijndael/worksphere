<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketDeadlineReminder extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Ticket $ticket
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $dueIn = $this->ticket->due_date->diffForHumans();

        return (new MailMessage)
            ->subject("Ticket Deadline Reminder: {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("This is a reminder that the following ticket is due {$dueIn}:")
            ->line("**{$this->ticket->title}**")
            ->line("Priority: {$this->ticket->priority->label()}")
            ->action('View Ticket', url("/tickets/{$this->ticket->public_id}"))
            ->line('Please take action to ensure this ticket is completed on time.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_deadline_reminder',
            'ticket_id' => $this->ticket->public_id,
            'ticket_title' => $this->ticket->title,
            'due_date' => $this->ticket->due_date->toISOString(),
            'priority' => $this->ticket->priority->value,
            'message' => "Ticket '{$this->ticket->title}' is due soon.",
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
            'type' => 'App\\Notifications\\TicketDeadlineReminder',
        ]);
    }
}
