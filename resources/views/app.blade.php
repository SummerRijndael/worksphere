<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $ogTitle ?? config('app.name', 'WorkSphere') }}</title>

    <!-- SEO -->
    <meta name="description"
        content="{{ $ogDescription ?? 'Unified Data, Seamless Workflow. WorkSphere connects your essential business tools into one powerful ecosystem.' }}">
    <meta name="keywords" content="WorkSphere, workflow, automation, efficiency, data sync">
    <meta name="author" content="WorkSphere">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name', 'WorkSphere') }}">
    <meta property="og:title" content="{{ $ogTitle ?? config('app.name', 'WorkSphere') }}">
    <meta property="og:description"
        content="{{ $ogDescription ?? 'Unified Data, Seamless Workflow. WorkSphere connects your essential business tools into one powerful ecosystem.' }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image"
        content="{{ $ogImage ?? (app(\App\Services\AppSettingsService::class)->get('app.opengraph') ?? asset('static/images/worksphere_brand.png')) }}">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle ?? config('app.name', 'WorkSphere') }}">
    <meta name="twitter:description"
        content="{{ $ogDescription ?? 'Unified Data, Seamless Workflow. WorkSphere connects your essential business tools into one powerful ecosystem.' }}">
    <meta name="twitter:image"
        content="{{ $ogImage ?? (app(\App\Services\AppSettingsService::class)->get('app.opengraph') ?? asset('static/images/worksphere_brand.png')) }}">

    <!-- Favicon -->
    <link rel="icon"
        href="{{ app(\App\Services\AppSettingsService::class)->get('app.favicon') ?? asset('favicon.ico') }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    <!-- Vite -->
    @vite(['resources/css/app.css', 'resources/js/app.ts'])

    <!-- Runtime Config -->
    <script nonce="{{ app(\App\Services\CSPService::class)->getNonce() }}">
        window.WorkSphere = {
            name: "{{ config('app.name', 'WorkSphere') }}",
            url: "{{ config('app.url') }}",
            features: {
                public_pricing_page_enabled: {{ app(\App\Services\AppSettingsService::class)->get('features.public_pricing_page.enabled', true) ? 'true' : 'false' }}
            }
        };
        // Legacy support
        window.CoreSync = window.WorkSphere;
    </script>
</head>

<body>
    <div id="app"></div>
</body>

</html>