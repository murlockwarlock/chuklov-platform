<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="color-scheme" content="light">
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head />
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>
