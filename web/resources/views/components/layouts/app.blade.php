@props(['title' => 'WasaaLifestyle', 'heading' => null])

@php
    $nav = auth()->user()->isAdmin()
        ? [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard'],
            ['label' => 'User Management', 'route' => null],
            ['label' => 'System Logs', 'route' => null],
            ['label' => 'Performance', 'route' => null],
        ]
        : [
            ['label' => 'Dashboard', 'route' => 'dashboard'],
            ['label' => 'Schedule', 'route' => 'schedules.week', 'active' => 'schedules.*'],
            ['label' => 'Activities', 'route' => 'activities.index', 'active' => 'activities.*'],
            ['label' => 'Recommendations', 'route' => null],
            ['label' => 'Settings', 'route' => null],
        ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-gray-900 antialiased">
    <div class="flex min-h-screen">
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-20 hidden w-60 border-r border-gray-200 bg-white p-4 md:static md:block">
            <a href="{{ route(auth()->user()->isAdmin() ? 'admin.dashboard' : 'dashboard') }}" class="mb-6 flex items-center gap-2 font-bold">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded bg-brand font-serif text-xs">WL</span>
                WasaaLifestyle
            </a>
            @if (auth()->user()->isAdmin())
                <p class="-mt-4 mb-4 text-xs uppercase tracking-wide text-gray-400">Admin portal</p>
            @endif

            <nav class="space-y-1" aria-label="Main">
                @foreach ($nav as $item)
                    @php($isActive = $item['route'] && request()->routeIs($item['active'] ?? $item['route']))
                    @if ($item['route'])
                        <a href="{{ route($item['route']) }}"
                           @class([
                               'block rounded-md px-3 py-2 text-sm',
                               'bg-brand/30 font-semibold' => $isActive,
                               'text-gray-600 hover:bg-gray-100' => ! $isActive,
                           ])
                           @if ($isActive) aria-current="page" @endif>
                            {{ $item['label'] }}
                        </a>
                    @else
                        <span class="flex items-center justify-between rounded-md px-3 py-2 text-sm text-gray-400" title="Coming soon">
                            {{ $item['label'] }}
                            <span class="text-[10px] uppercase">soon</span>
                        </span>
                    @endif
                @endforeach
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
                <div class="flex items-center gap-3">
                    <button type="button" data-sidebar-toggle class="rounded-md border border-gray-300 px-2 py-1 text-sm md:hidden" aria-controls="sidebar" aria-label="Toggle menu">
                        Menu
                    </button>
                    <h1 class="text-lg font-semibold">{{ $heading ?? $title }}</h1>
                </div>

                <div class="flex items-center gap-4 text-sm">
                    <span class="text-gray-600">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-gray-600 hover:text-gray-900">Log out</button>
                    </form>
                </div>
            </header>

            <main class="flex-1 p-6">
                @if (session('status'))
                    <div role="status" class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
                        {{ session('status') }}
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
