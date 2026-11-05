<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f6f3">
    <meta name="description" content="Plan a week that fits your routines, availability, and real-life commitments with WasaaLifestyle.">
    <title>WasaaLifestyle | Make room for what matters</title>
    <script src="{{ asset('theme.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="overflow-x-hidden bg-canvas font-sans text-gray-900 antialiased">
    <a href="#main-content" class="sr-only z-50 rounded-lg bg-white px-4 py-3 text-sm font-semibold text-gray-900 focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>

    <header class="landing-header sticky top-0 z-30 border-b border-white/70 bg-[#f7f6f3]/90 backdrop-blur-xl">
        <div class="mx-auto flex min-h-24 w-full max-w-7xl items-center justify-between gap-3 px-5 py-5 sm:min-h-28 sm:px-8 lg:px-10">
        <a href="{{ route('home') }}" class="inline-flex items-center gap-3 font-bold tracking-tight text-gray-950">
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-300 via-brand to-orange-400 text-amber-950 shadow-sm">
                <svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17h16M6.5 14a5.5 5.5 0 0 1 11 0M12 3v2M4.9 6l1.5 1.5M19.1 6l-1.5 1.5"/><path d="M8.5 17v2m7-2v2"/></svg>
            </span>
            <span class="text-base sm:text-lg">Wasaa<span class="hidden sm:inline">Lifestyle</span></span>
        </a>

        <nav class="hidden items-center gap-7 text-sm font-medium text-gray-600 md:flex" aria-label="Page">
            <a href="#how-it-works" class="rounded-md px-2 py-1 hover:text-gray-950">How it works</a>
            <a href="#features" class="rounded-md px-2 py-1 hover:text-gray-950">What you can do</a>
        </nav>

        <div class="flex items-center gap-2 sm:gap-3">
            <x-theme-toggle />
            @auth
                <a href="{{ route(auth()->user()->isAdmin() ? 'admin.dashboard' : 'dashboard') }}" class="btn-secondary !px-3 !py-2 text-xs sm:!px-4 sm:text-sm">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-white hover:text-gray-950 sm:px-4 sm:text-sm">Log in</a>
                <a href="{{ route('register') }}" class="btn-primary !px-3 !py-2 text-xs sm:!px-4 sm:text-sm">Get started</a>
            @endauth
        </div>
        </div>
    </header>

    <main id="main-content">
        <section class="wasaa-hero-band relative mx-auto grid w-full max-w-7xl items-center gap-14 px-5 pb-16 pt-8 sm:px-8 sm:pb-20 lg:grid-cols-[1fr_0.92fr] lg:gap-16 lg:px-10 lg:pb-28 lg:pt-12">
            <div aria-hidden="true" class="pointer-events-none absolute -left-56 top-16 h-96 w-96 rounded-full bg-brand/15 blur-3xl"></div>

            <div class="wasaa-rise-in relative z-10 max-w-2xl">
                <p class="mb-6 inline-flex items-center gap-2 rounded-full border border-amber-200 bg-white/75 px-3.5 py-2 text-[11px] font-bold uppercase tracking-[0.14em] text-amber-900 shadow-sm">
                    <span class="h-2 w-2 rounded-full bg-brand-dark"></span>
                    A calmer way to plan your week
                </p>
                <h1 class="max-w-xl text-5xl font-semibold leading-[1.02] tracking-[-0.045em] text-gray-950 sm:text-6xl lg:text-7xl">Make room for <span class="bg-gradient-to-r from-amber-800 via-orange-600 to-amber-700 bg-clip-text text-transparent">what matters</span>.</h1>
                <p class="mt-7 max-w-xl text-base leading-7 text-gray-600 sm:text-lg sm:leading-8">Wasaa brings your routines, availability, and commitments into one thoughtful plan—so your week feels possible, not packed.</p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                    @auth
                        <a href="{{ route(auth()->user()->isAdmin() ? 'admin.dashboard' : 'dashboard') }}" class="btn-primary">Go to your dashboard <x-icon name="arrow-right" class="h-4 w-4" /></a>
                    @else
                        <a href="{{ route('register') }}" class="btn-primary">Create your account <x-icon name="arrow-right" class="h-4 w-4" /></a>
                    @endauth
                    <a href="#how-it-works" class="btn-secondary">See how Wasaa works <x-icon name="arrow-down" class="h-4 w-4" /></a>
                </div>

                <p class="mt-5 text-xs leading-5 text-gray-500">Your availability guides the plan. You review suggestions before anything is added.</p>
            </div>

            <div class="wasaa-rise-in relative mx-auto w-full max-w-xl lg:justify-self-end" style="animation-delay: 100ms">
                <div aria-hidden="true" class="absolute -right-5 -top-8 h-24 w-24 rounded-full border border-brand/50"></div>
                <div aria-hidden="true" class="absolute -bottom-7 -left-6 h-20 w-20 rounded-full bg-brand/25"></div>

                <div class="wasaa-float-soft relative rounded-[1.75rem] border border-white bg-white p-4 shadow-2xl shadow-gray-900/10 sm:p-6">
                    <div class="flex items-start justify-between gap-4 px-1 pb-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-gray-400">A week with breathing room</p>
                            <h2 class="mt-1 text-lg font-semibold tracking-tight text-gray-950">Your plan, at a glance</h2>
                        </div>
                        <span class="rounded-full bg-brand/20 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide text-amber-900">Preview</span>
                    </div>

                    <div class="grid grid-cols-7 gap-1.5 border-y border-gray-100 py-4 text-center">
                        @foreach ([['M', '12'], ['T', '13'], ['W', '14'], ['T', '15'], ['F', '16'], ['S', '17'], ['S', '18']] as [$day, $date])
                            <div @class(['rounded-xl py-2', 'bg-gradient-to-br from-amber-300 via-brand to-orange-300 text-gray-950 shadow-sm' => $day === 'W' && $date === '14', 'text-gray-500' => ! ($day === 'W' && $date === '14')])>
                                <span class="block text-[9px] font-semibold uppercase">{{ $day }}</span>
                                <span class="mt-1 block text-xs font-bold">{{ $date }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 space-y-3">
                        <div class="flex items-center gap-3 rounded-2xl bg-canvas p-3.5">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-100 to-brand/50 text-amber-900">
                                <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.5"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold text-gray-900">Morning walk</p>
                                <p class="mt-1 text-[10px] text-gray-500">Movement · 7:30 AM</p>
                            </div>
                            <span class="rounded-full bg-white px-2 py-1 text-[9px] font-semibold text-gray-600">30 min</span>
                        </div>

                        <div class="flex items-center gap-3 rounded-2xl bg-canvas p-3.5">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-gray-600 shadow-sm">
                                <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M8 9h8M8 13h5m-5 4h8"/></svg>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold text-gray-900">Focus block</p>
                                <p class="mt-1 text-[10px] text-gray-500">Work · 10:00 AM</p>
                            </div>
                            <span class="rounded-full bg-white px-2 py-1 text-[9px] font-semibold text-gray-600">60 min</span>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-amber-200/80 bg-gradient-to-br from-[#fff9ed] via-amber-50 to-orange-50 p-4 shadow-sm shadow-amber-900/5">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-xl bg-gradient-to-br from-amber-300 to-orange-400 text-amber-950 shadow-sm" aria-hidden="true">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3Z"/><path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16Z"/></svg>
                            </span>
                            <p class="text-xs font-semibold text-gray-900">A suggestion that fits</p>
                        </div>
                        <p class="mt-2 pl-8 text-[11px] leading-5 text-gray-600">Try moving reading to a free evening window. Review it before adding it to your schedule.</p>
                    </div>
                </div>

                <p class="mt-3 text-center text-[10px] text-gray-500">Illustrative preview — your schedule stays yours to shape.</p>
            </div>
        </section>

        <section id="features" class="border-y border-[#e8e2d9] bg-white/75 py-16 sm:py-20">
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[0.15em] text-amber-800">Less juggling, more intention</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-gray-950 sm:text-4xl">A helpful rhythm, built around your life.</h2>
                    <p class="mt-4 text-sm leading-6 text-gray-600 sm:text-base">A few practical tools work together to make planning clearer, while you stay in control.</p>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="wasaa-feature-card group rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:-translate-y-1 hover:border-brand/70 hover:shadow-lg">
                        <span class="wasaa-feature-icon inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-100 via-brand/30 to-orange-100 text-amber-900 shadow-inner" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.5 2"/></svg></span>
                        <h3 class="mt-5 text-sm font-semibold text-gray-900">Plan around your time</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600">Set the hours that work for you and organize routines alongside one-off tasks.</p>
                    </article>
                    <article class="wasaa-feature-card group rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:-translate-y-1 hover:border-brand/70 hover:shadow-lg">
                        <span class="wasaa-feature-icon inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-100 via-brand/30 to-orange-100 text-amber-900 shadow-inner" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="11" height="14" rx="2.5"/><rect x="9.5" y="5" width="11" height="14" rx="2.5"/><path d="M7 9h2m7 0h2"/></svg></span>
                        <h3 class="mt-5 text-sm font-semibold text-gray-900">Spot conflicts early</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600">See when plans overlap and review conflicts before confirming a schedule.</p>
                    </article>
                    <article class="wasaa-feature-card group rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:-translate-y-1 hover:border-brand/70 hover:shadow-lg">
                        <span class="wasaa-feature-icon inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-100 via-brand/30 to-orange-100 text-amber-900 shadow-inner" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.9 5.4L19 10l-5.1 1.7L12 17l-1.9-5.3L5 10l5.1-1.6L12 3Z"/><path d="m19 16 .8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16Z"/></svg></span>
                        <h3 class="mt-5 text-sm font-semibold text-gray-900">Explore better time slots</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600">Get schedule suggestions based on your routines, preferences, and existing plans.</p>
                    </article>
                    <article class="wasaa-feature-card group rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:-translate-y-1 hover:border-brand/70 hover:shadow-lg">
                        <span class="wasaa-feature-icon inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-100 via-brand/30 to-orange-100 text-amber-900 shadow-inner" aria-hidden="true"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5m0 14h16"/><path d="m7 15 3-4 3 2 5-6"/><path d="M15 7h3v3"/></svg></span>
                        <h3 class="mt-5 text-sm font-semibold text-gray-900">Notice your progress</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600">Log completed activities and see your consistency over time.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="how-it-works" class="mx-auto max-w-7xl px-5 py-16 sm:px-8 sm:py-20 lg:px-10">
            <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:items-start">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.15em] text-amber-800">Simple to start</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight text-gray-950 sm:text-4xl">Your week, one step at a time.</h2>
                    <p class="mt-4 max-w-md text-sm leading-6 text-gray-600 sm:text-base">Start with what matters most. Wasaa helps you make a plan, then leaves the choices in your hands.</p>
                    @guest
                        <a href="{{ route('register') }}" class="btn-primary mt-6">Start planning <x-icon name="arrow-right" class="h-4 w-4" /></a>
                    @endguest
                </div>

                <ol class="space-y-4">
                    <li class="flex gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand text-sm font-bold">01</span>
                        <div><h3 class="text-sm font-semibold text-gray-900">Add your routines and availability</h3><p class="mt-1 text-sm leading-6 text-gray-600">Tell Wasaa what you want to make time for and when you’re usually free.</p></div>
                    </li>
                    <li class="flex gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand text-sm font-bold">02</span>
                        <div><h3 class="text-sm font-semibold text-gray-900">Build and review your schedule</h3><p class="mt-1 text-sm leading-6 text-gray-600">Arrange tasks, check for conflicts, and consider time-slot recommendations.</p></div>
                    </li>
                    <li class="flex gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand text-sm font-bold">03</span>
                        <div><h3 class="text-sm font-semibold text-gray-900">Log what you complete</h3><p class="mt-1 text-sm leading-6 text-gray-600">Keep track of activity history and adjust your plan as life changes.</p></div>
                    </li>
                </ol>
            </div>
        </section>

        <section class="px-5 pb-16 sm:px-8 sm:pb-20 lg:px-10">
            <div class="mx-auto flex max-w-7xl flex-col items-start justify-between gap-6 rounded-[1.75rem] bg-gray-950 px-6 py-8 text-white sm:px-10 sm:py-10 md:flex-row md:items-center">
                <div class="max-w-xl">
                    <p class="text-xs font-bold uppercase tracking-[0.15em] text-brand">Make your next week a little easier</p>
                    <h2 class="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">Start with one routine. Build from there.</h2>
                </div>
                @guest
                    <a href="{{ route('register') }}" class="btn-primary shrink-0">Create your account <x-icon name="arrow-right" class="h-4 w-4" /></a>
                @else
                    <a href="{{ route(auth()->user()->isAdmin() ? 'admin.dashboard' : 'dashboard') }}" class="btn-primary shrink-0">Open Wasaa <x-icon name="arrow-right" class="h-4 w-4" /></a>
                @endguest
            </div>
        </section>
    </main>

    <footer class="border-t border-[#e8e2d9] bg-white/60">
        <div class="mx-auto flex max-w-7xl flex-col gap-3 px-5 py-6 text-xs text-gray-500 sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-10">
            <span class="font-semibold text-gray-700">WasaaLifestyle</span>
            <span>Thoughtful planning for real life.</span>
            <a href="{{ route('login') }}" class="font-medium text-gray-600 underline decoration-gray-300 underline-offset-4 hover:text-gray-950">Account login</a>
        </div>
    </footer>
</body>
</html>
