<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Call — {{ config('app.name', 'WorkSphere') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Favicon -->
    <link rel="icon"
        href="{{ app(\App\Services\AppSettingsService::class)->get('app.favicon') ?? asset('favicon.ico') }}">

    <!-- Vite (standalone call bundle) -->
    @vite(['resources/css/call.css', 'resources/js/call.ts'])

    <!-- Runtime Config -->
    <script nonce="{{ app(\App\Services\CSPService::class)->getNonce() }}">
        window.WorkSphere = {
            name: "{{ config('app.name', 'WorkSphere') }}",
            url: "{{ config('app.url') }}",
        };
    </script>
</head>

<body>
    <div id="call-app"></div>
</body>

</html>
