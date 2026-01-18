<x-mail::message>
    # Secure Backup Download

    You (or someone using your account) requested to download **{{ $filesCount }}** backup file(s) for the following
    reason:

    > {{ $reason }}

    To access the contents of the downloaded ZIP file, you must use the following password:

    <x-mail::panel>
        # {{ $password }}
    </x-mail::panel>

    This password was auto-generated and is unique to this download request.

    If you did not request this download, please contact your system administrator immediately.

    Thanks,<br>
    {{ config('app.name') }}
</x-mail::message>