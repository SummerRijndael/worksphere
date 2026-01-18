<?php

namespace App\Notifications;

use App\Models\Event;
use App\Services\CalendarExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $icsContent;

    protected string $googleCalendarUrl;

    protected string $outlookUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Event $event,
        public bool $isUpdate = false
    ) {
        $exportService = app(CalendarExportService::class);
        $this->icsContent = $exportService->generateIcs($event);
        $this->googleCalendarUrl = $exportService->getGoogleCalendarUrl($event);
        $this->outlookUrl = $exportService->getOutlookUrl($event);
    }

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
        $organizer = $this->event->organizer;
        $subject = $this->isUpdate
            ? 'Event Updated: '.$this->event->title
            : 'Invitation: '.$this->event->title;

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.'!')
            ->line($this->isUpdate
                ? $organizer->name.' has updated an event you are invited to.'
                : $organizer->name.' has invited you to an event.')
            ->line('')
            ->line('**'.$this->event->title.'**')
            ->line('📅 '.$this->event->start_time->format('l, M d, Y'))
            ->line('🕐 '.($this->event->is_all_day
                ? 'All day'
                : $this->event->start_time->format('g:i A').
                    ($this->event->end_time ? ' - '.$this->event->end_time->format('g:i A') : '')))
            ->line('📍 '.($this->event->location ?? 'No location specified'));

        if ($this->event->description) {
            $message->line('')->line($this->event->description);
        }

        $message
            ->line('')
            ->line('**Add to your calendar:**')
            ->action('Add to Google Calendar', $this->googleCalendarUrl)
            ->line('[Add to Outlook]('.$this->outlookUrl.')')
            ->line('')
            ->line('Open the attached .ics file to add this event to Apple Calendar or other calendar applications.')
            ->attachData($this->icsContent, 'event.ics', [
                'mime' => 'text/calendar; charset=UTF-8; method=REQUEST',
            ]);

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'event_invite',
            'event_id' => $this->event->id,
            'title' => $this->isUpdate
                ? 'Event Updated: '.$this->event->title
                : 'Event Invitation: '.$this->event->title,
            'message' => $this->event->organizer->name.' has '.
                ($this->isUpdate ? 'updated' : 'invited you to').
                ' "'.$this->event->title.'"',
            'start_time' => $this->event->start_time,
            'location' => $this->event->location,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): \Illuminate\Notifications\Messages\BroadcastMessage
    {
        return new \Illuminate\Notifications\Messages\BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }
}
