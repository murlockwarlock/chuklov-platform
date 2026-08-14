<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light">
        <link rel="icon" type="image/svg+xml" href="{{ asset('brand/chuklov-mark.svg') }}">
        <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('brand/chuklov-app-icon-v2.png') }}">
        <link rel="apple-touch-icon" sizes="512x512" href="{{ asset('brand/chuklov-app-icon-v2.png') }}">
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head />
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>
