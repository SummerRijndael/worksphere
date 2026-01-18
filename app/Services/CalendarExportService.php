<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TeamEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CalendarExportService
{
    /**
     * Generate ICS content for a single event.
     */
    public function generateIcs(Event|TeamEvent $event): string
    {
        $uid = $this->generateEventUid($event);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('app.name').'//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$this->formatDateTime(now()),
            $this->formatDateTimeField('DTSTART', $event->start_time, $event->is_all_day),
        ];

        if ($event->end_time) {
            $lines[] = $this->formatDateTimeField('DTEND', $event->end_time, $event->is_all_day);
        }

        $lines[] = 'SUMMARY:'.$this->escapeIcsText($event->title);

        if ($event->description) {
            $lines[] = 'DESCRIPTION:'.$this->escapeIcsText($event->description);
        }

        if ($event->location) {
            $lines[] = 'LOCATION:'.$this->escapeIcsText($event->location);
        }

        // Add organizer
        $organizer = $event instanceof TeamEvent ? $event->creator : $event->organizer;
        if ($organizer) {
            $lines[] = 'ORGANIZER;CN='.$this->escapeIcsText($organizer->name).':mailto:'.$organizer->email;
        }

        // Add attendees/participants
        $participants = $event instanceof TeamEvent ? $event->participants : $event->attendees;
        foreach ($participants as $participant) {
            $lines[] = 'ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN='.
                $this->escapeIcsText($participant->name).':mailto:'.$participant->email;
        }

        // Add alarm/reminder if set
        if ($event->reminder_minutes_before) {
            $lines = array_merge($lines, $this->generateAlarm($event->reminder_minutes_before));
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'SEQUENCE:0';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * Generate ICS content for multiple events (export).
     */
    public function generateMultipleIcs(Collection $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//'.config('app.name').'//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($events as $event) {
            $uid = $this->generateEventUid($event);
            $eventLines = [
                'BEGIN:VEVENT',
                'UID:'.$uid,
                'DTSTAMP:'.$this->formatDateTime(now()),
                $this->formatDateTimeField('DTSTART', $event->start_time, $event->is_all_day),
            ];

            if ($event->end_time) {
                $eventLines[] = $this->formatDateTimeField('DTEND', $event->end_time, $event->is_all_day);
            }

            $eventLines[] = 'SUMMARY:'.$this->escapeIcsText($event->title);

            if ($event->description) {
                $eventLines[] = 'DESCRIPTION:'.$this->escapeIcsText($event->description);
            }

            if ($event->location) {
                $eventLines[] = 'LOCATION:'.$this->escapeIcsText($event->location);
            }

            $eventLines[] = 'STATUS:CONFIRMED';
            $eventLines[] = 'END:VEVENT';

            $lines = array_merge($lines, $eventLines);
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * Generate Google Calendar URL for an event.
     */
    public function getGoogleCalendarUrl(Event|TeamEvent $event): string
    {
        $params = [
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $this->formatGoogleDates($event),
        ];

        if ($event->description) {
            $params['details'] = $event->description;
        }

        if ($event->location) {
            $params['location'] = $event->location;
        }

        return 'https://calendar.google.com/calendar/render?'.http_build_query($params);
    }

    /**
     * Generate Outlook Web URL for an event.
     */
    public function getOutlookUrl(Event|TeamEvent $event): string
    {
        $params = [
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $event->title,
            'startdt' => $event->start_time->toIso8601String(),
        ];

        if ($event->end_time) {
            $params['enddt'] = $event->end_time->toIso8601String();
        }

        if ($event->description) {
            $params['body'] = $event->description;
        }

        if ($event->location) {
            $params['location'] = $event->location;
        }

        if ($event->is_all_day) {
            $params['allday'] = 'true';
        }

        return 'https://outlook.live.com/calendar/0/deeplink/compose?'.http_build_query($params);
    }

    /**
     * Generate event UID for ICS.
     */
    protected function generateEventUid(Event|TeamEvent $event): string
    {
        $type = $event instanceof TeamEvent ? 'team' : 'personal';
        $publicId = $event->public_id ?? $event->id;

        return "{$type}-{$publicId}@".parse_url(config('app.url'), PHP_URL_HOST);
    }

    /**
     * Format datetime for ICS (UTC format).
     */
    protected function formatDateTime(Carbon $date): string
    {
        return $date->utc()->format('Ymd\THis\Z');
    }

    /**
     * Format date only for all-day events.
     */
    protected function formatDate(Carbon $date): string
    {
        return $date->format('Ymd');
    }

    /**
     * Format DTSTART/DTEND field with appropriate value type.
     */
    protected function formatDateTimeField(string $field, Carbon $date, bool $allDay = false): string
    {
        if ($allDay) {
            return $field.';VALUE=DATE:'.$this->formatDate($date);
        }

        return $field.':'.$this->formatDateTime($date);
    }

    /**
     * Format dates for Google Calendar URL.
     */
    protected function formatGoogleDates(Event|TeamEvent $event): string
    {
        if ($event->is_all_day) {
            $start = $event->start_time->format('Ymd');
            $end = $event->end_time
                ? $event->end_time->addDay()->format('Ymd')
                : $event->start_time->addDay()->format('Ymd');

            return $start.'/'.$end;
        }

        $start = $event->start_time->utc()->format('Ymd\THis\Z');
        $end = $event->end_time
            ? $event->end_time->utc()->format('Ymd\THis\Z')
            : $event->start_time->addHour()->utc()->format('Ymd\THis\Z');

        return $start.'/'.$end;
    }

    /**
     * Escape text for ICS format.
     */
    protected function escapeIcsText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);

        return $text;
    }

    /**
     * Generate VALARM component for reminder.
     */
    protected function generateAlarm(int $minutesBefore): array
    {
        return [
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'DESCRIPTION:Reminder',
            'TRIGGER:-PT'.$minutesBefore.'M',
            'END:VALARM',
        ];
    }
}
