<x-mail::message>
# Event Cancelled

The following event has been cancelled and removed from the calendar:

**{{ $event->title }}**

<x-mail::panel>
📅 **Original Date:** {{ $event->start_time->format('l, M d, Y') }}  
🕐 **Original Time:** {{ $event->is_all_day ? 'All day' : $event->start_time->format('g:i A') . ($event->end_time ? ' - ' . $event->end_time->format('g:i A') : '') }}
</x-mail::panel>

This event is no longer scheduled. If this was a mistake or you have questions, please contact the organizer.

<x-mail::button :url="config('app.url') . '/calendar'">
View My Calendar
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
