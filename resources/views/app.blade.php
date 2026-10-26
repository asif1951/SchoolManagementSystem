<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- CSRF Token -->
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Website Title -->
        <title inertia>Childhome Academy</title>

        <!-- Favicon from backend (school logo) -->
        @if(!empty($page['props']['school']['logo']))
            <link rel="icon" type="image/png" href="{{ asset('storage/' . $page['props']['school']['logo']) }}">
            <link rel="shortcut icon" type="image/png" href="{{ asset('storage/' . $page['props']['school']['logo']) }}">
            <link rel="apple-touch-icon" href="{{ asset('storage/' . $page['props']['school']['logo']) }}">
        @endif

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>

    <body class="font-sans antialiased">
        @inertia
    </body>
</html>