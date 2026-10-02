<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- No `inertia` attribute: Inertia owns that element and deletes it when no
         page supplies a <Head> title, which blanks the browser tab. The product
         name is not per-page, so the server renders it and owns it outright. --}}
    <title>{{ config('app.name', 'Possible Words') }}</title>

    <!-- Favicon -->
    @php
        $faviconUrl = config('filesystems.default') === 's3'
            ? \Illuminate\Support\Facades\Storage::disk('s3')->url('favicon.ico')
            : '/favicon.ico';
    @endphp
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" type="image/x-icon" href="{{ $faviconUrl }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
