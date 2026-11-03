@props(['title' => 'WasaaLifestyle', 'heading' => null])

@php
    $user = auth()->user();
    $nav = $user->isAdmin()
        ? [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => 'dashboard'],
            ['label' => 'User Management', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'icon' => 'users'],
            ['label' => 'System Logs', 'route' => 'admin.audit-logs.index', 'active' => 'admin.audit-logs.*', 'icon' => 'clipboard'],
            ['label' => 'Performance', 'route' => 'admin.analytics', 'active' => 'admin.analytics', 'icon' => 'chart'],
        ]
        : [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['label' => 'Schedule', 'route' => 'schedules.week', 'active' => 'schedules.*', 'icon' => 'calendar'],
            ['label' => 'Activities', 'route' => 'activities.index', 'active' => 'activities.*', 'icon' => 'activities'],
            ['label' => 'Recommendations', 'route' => 'recommendations.index', 'icon' => 'sparkles'],
            ['label' => 'Settings', 'route' => 'settings.edit', 'icon' => 'settings'],
            ['label' => 'Notifications', 'route' => 'notifications.index', 'active' => 'notifications.*', 'icon' => 'bell'],
        ];
    $unreadNotificationCount = $user->isAdmin() ? 0 : $user->notifications()->where('is_read', false)->count();
    $initials = collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $sidebarQuotes = [
        'Make space for what matters, then let the rest wait.',
        'Your pace is part of the plan.',
        'A good week leaves room to breathe.',
        'Progress is showing up, not doing everything.',
        'Plan for the life you have, not the perfect day.',
        'Rest belongs on the schedule, too.',
        'One thoughtful choice can change the shape of a day.',
        'Small steps still move you forward.',
    ];
    $sidebarQuoteIndex = array_rand($sidebarQuotes);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f6f3">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-shell min-h-screen font-sans text-gray-900 antialiased">
    <button type="button" data-sidebar-backdrop class="sidebar-backdrop fixed inset-0 z-30 hidden bg-gray-950/40 backdrop-blur-[2px] md:hidden" aria-label="Close navigation"></button>

    <aside id="sidebar" class="app-sidebar fixed inset-y-0 left-0 z-40 flex w-[17rem] -translate-x-full flex-col border-r border-white/70 bg-white px-4 py-5 shadow-2xl shadow-gray-900/10 transition-transform duration-300 md:translate-x-0">
        <a href="{{ route($user->isAdmin() ? 'admin.dashboard' : 'dashboard') }}" class="mb-8 flex items-center gap-3 rounded-2xl px-2 py-1.5 font-bold tracking-tight text-gray-900">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-300 via-brand to-orange-400 text-gray-950 shadow-md shadow-amber-900/15"><x-icon name="sunrise" class="h-6 w-6" /></span>
            <span><span class="block text-[15px]">Wasaa</span><span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-[.19em] text-gray-500">Make room for life</span></span>
        </a>

        <div class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[.18em] text-gray-400">{{ $user->isAdmin() ? 'Admin portal' : 'Your space' }}</div>
        <nav class="space-y-1.5" aria-label="Main navigation">
            @foreach ($nav as $item)
                @php($isActive = request()->routeIs($item['active'] ?? $item['route']))
                <a href="{{ route($item['route']) }}" @class([
                    'app-nav-link group flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13px] font-medium',
                    'is-active' => $isActive,
                    'text-gray-600 hover:bg-gray-100/80 hover:text-gray-950' => ! $isActive,
                ]) @if ($isActive) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" class="h-[18px] w-[18px] shrink-0 text-gray-400 group-hover:text-amber-800" />
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if ($item['route'] === 'notifications.index' && $unreadNotificationCount > 0)
                        <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-amber-200 px-1.5 py-0.5 text-[10px] font-bold text-amber-950" aria-label="{{ $unreadNotificationCount }} unread notifications">{{ $unreadNotificationCount }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <aside class="sidebar-quote mt-auto rounded-2xl border border-amber-200/80 p-4" aria-label="A planning reminder">
            <div class="flex items-center justify-between gap-3">
                <p class="text-[10px] font-bold uppercase tracking-[.16em] text-amber-900/75">A thought for today</p>
                <button type="button" data-quote-randomize data-quotes="{{ json_encode($sidebarQuotes) }}" data-current-quote="{{ $sidebarQuoteIndex }}" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-amber-900 transition hover:bg-white/80" aria-label="Show another thought" title="Show another thought">
                    <x-icon name="refresh" class="h-4 w-4" />
                </button>
            </div>
            <p data-sidebar-quote aria-live="polite" class="mt-2 text-xs font-medium leading-5 text-gray-800">{{ $sidebarQuotes[$sidebarQuoteIndex] }}</p>
        </aside>
    </aside>

    <div class="min-h-screen min-w-0 md:ml-[17rem]">
        <header class="app-topbar sticky top-0 z-20 flex min-h-[4.5rem] items-center justify-between gap-3 border-b border-white/70 px-4 py-2.5 sm:px-7 lg:px-10">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" data-sidebar-toggle class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-700 shadow-sm md:hidden" aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation"><x-icon name="menu" class="h-5 w-5" /></button>
                <div class="min-w-0"><p class="hidden text-[10px] font-semibold uppercase tracking-[.16em] text-amber-800 sm:block">{{ $user->isAdmin() ? 'Wasaa · workspace' : 'Your week, in view' }}</p><h1 class="truncate text-base font-semibold tracking-tight text-gray-950 sm:text-xl">{{ $heading ?? $title }}</h1></div>
            </div>

            <details class="account-menu relative shrink-0" data-account-menu>
                <summary class="flex cursor-pointer list-none items-center gap-2 rounded-2xl border border-white/80 bg-white/70 py-1.5 pl-1.5 pr-2.5 shadow-sm backdrop-blur-xl transition hover:border-amber-200 hover:bg-white sm:gap-3 sm:pl-2">
                    @if ($user->profile_photo_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($user->profile_photo_path) }}" alt="" class="h-9 w-9 rounded-xl object-cover">
                    @else
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-amber-100 to-orange-200 text-xs font-bold text-amber-950">{{ $initials }}</span>
                    @endif
                    <span class="hidden max-w-36 text-left sm:block"><span class="block truncate text-xs font-semibold text-gray-900">{{ $user->name }}</span><span class="mt-0.5 block text-[10px] text-gray-500">{{ $user->email }}</span></span>
                    <x-icon name="chevron" class="h-4 w-4 text-gray-400 transition-transform" />
                </summary>
                <div class="account-menu-panel absolute right-0 top-[calc(100%+0.7rem)] z-30 w-64 rounded-2xl border border-white/80 p-2 shadow-xl shadow-gray-900/10">
                    <div class="border-b border-gray-100 px-3 py-2.5"><p class="truncate text-xs font-semibold text-gray-900">{{ $user->name }}</p><p class="mt-1 truncate text-[11px] text-gray-500">{{ $user->email }}</p></div>
                    <a href="{{ route('settings.edit') }}" class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-xs font-medium text-gray-700 hover:bg-amber-50 hover:text-gray-950"><x-icon name="settings" class="h-4 w-4 text-gray-400" />Account settings</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-xs font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-950"><x-icon name="logout" class="h-4 w-4 text-gray-400" />Log out</button></form>
                </div>
            </details>
        </header>

        <main class="app-main min-h-[calc(100vh-4.5rem)] px-4 py-6 sm:px-7 sm:py-8 lg:px-10">
            <div class="mx-auto w-full max-w-[88rem] space-y-5">
                @if (session('status'))
                    <div role="status" class="app-status rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 shadow-sm">{{ session('status') }}</div>
                @endif
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
