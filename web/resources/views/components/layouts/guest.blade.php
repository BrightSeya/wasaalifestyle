@props(['title' => 'WasaaLifestyle', 'subtitle' => ''])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-gray-900 antialiased">
    <main class="flex min-h-screen items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-xl bg-gray-200 p-8 shadow-sm">
            <div class="mb-6 text-center">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-brand font-serif font-bold">WL</span>
                <h1 class="mt-3 text-xl font-bold">WasaaLifestyle</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>
            </div>

            {{ $slot }}
        </div>
    </main>
</body>
</html>
