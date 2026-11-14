<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2f4bd0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>{{ config('app.name', 'StockPilot') }}</title>
    <script>
        // Apply the stored theme before first paint to avoid a flash.
        (function () {
            try {
                var stored = localStorage.getItem('sp-theme');
                var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (dark) document.documentElement.classList.add('dark');
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    {!! \App\Support\ViteFonts::tags() !!}
</head>
<body class="h-full bg-surface-sunken antialiased">
    <div id="app"></div>
</body>
</html>
