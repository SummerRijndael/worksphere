<x-mail::message>
@if($isUpdate)
# An Event Has Been Updated
@else
# You Have Been Invited to an Event
@endif

**{{ $event->title }}**

<x-mail::panel>
📅 **Date:** {{ $event->start_time->format('l, M d, Y') }}  
🕐 **Time:** {{ $event->is_all_day ? 'All day' : $event->start_time->format('g:i A') . ($event->end_time ? ' - ' . $event->end_time->format('g:i A') : '') }}  
📍 **Location:** {{ $event->location ?? 'No location specified' }}
</x-mail::panel>

@if($event->description)
{{ $event->description }}
@endif


@if($event instanceof \App\Models\Event && $event->meeting)
---

**Join Video Meeting:**

<x-mail::button :url="config('app.url') . '/meetings/' . $event->meeting->public_id . '/join'" color="primary">
Join Meeting
</x-mail::button>

<x-mail::panel>
**Meeting ID:** {{ $event->meeting->public_id }}  
@if($event->meeting->password)
@php($meetingPasscode = $event->meeting->revealPassword())
@if($meetingPasscode)
**Passcode:** {{ $meetingPasscode }}
@else
**Passcode:** Required (ask the host for the current passcode)
@endif
@endif
</x-mail::panel>

@endif

---

**Add to your calendar:**

<x-mail::button :url="$googleCalendarUrl" color="primary">
Add to Google Calendar
</x-mail::button>

<x-mail::button :url="$outlookUrl" color="success">
Add to Outlook
</x-mail::button>

Or open the attached `.ics` file to add this event to Apple Calendar or other calendar applications.

---

<x-mail::button :url="config('app.url') . '/teams/' . $event->team->public_id">
View in {{ config('app.name') }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
