@props(['title' => 'WasaaLifestyle'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-gray-900 antialiased">
    <header class="flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
        <span class="flex items-center gap-2 font-bold">
            <span class="inline-flex h-7 w-7 items-center justify-center rounded bg-brand font-serif text-xs">WL</span>
            WasaaLifestyle
        </span>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm text-gray-600 hover:text-gray-900">Log out</button>
        </form>
    </header>

    <main class="p-6">
        {{ $slot }}
    </main>
</body>
</html>
