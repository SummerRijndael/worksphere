<?php

namespace App\Mail;

use App\Models\Event;
use App\Services\CalendarExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public string $googleCalendarUrl;

    public string $outlookUrl;

    protected string $icsContent;

    /**
     * Create a new message instance.
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
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->isUpdate
            ? 'Event Updated: '.$this->event->title
            : 'Invitation: '.$this->event->title;

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.calendar.invitation',
            with: [
                'event' => $this->event,
                'isUpdate' => $this->isUpdate,
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
        return [
            Attachment::fromData(fn () => $this->icsContent, 'event.ics')
                ->withMime('text/calendar; charset=UTF-8; method=REQUEST'),
        ];
    }
}
