@props(['title' => 'WasaaLifestyle', 'heading' => 'Welcome to Wasaa', 'subtitle' => 'Plan your week with more intention.'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f6f3">
    <title>{{ $title }}</title>
    <script src="{{ asset('theme.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas font-sans text-gray-900 antialiased">
    <div class="min-h-screen lg:grid lg:grid-cols-[minmax(0,1.05fr)_minmax(420px,0.95fr)]">
        <aside class="wasaa-auth-panel relative hidden min-h-screen overflow-hidden px-10 py-9 lg:flex lg:flex-col xl:px-16">
            <div aria-hidden="true" class="absolute -right-32 -top-28 h-96 w-96 rounded-full border border-gray-900/10"></div>
            <div aria-hidden="true" class="absolute -bottom-48 -left-36 h-[34rem] w-[34rem] rounded-full border border-gray-900/10"></div>
            <div aria-hidden="true" class="absolute right-24 top-1/3 h-3 w-3 rounded-full bg-white/50"></div>

            <a href="{{ route('home') }}" class="relative z-10 inline-flex w-fit items-center gap-3 font-bold tracking-tight text-gray-900">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-gray-900/10 bg-white/50 text-gray-900 shadow-sm"><svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17h16M6.5 14a5.5 5.5 0 0 1 11 0M12 3v2M4.9 6l1.5 1.5M19.1 6l-1.5 1.5"/><path d="M8.5 17v2m7-2v2"/></svg></span>
                <span class="text-lg">WasaaLifestyle</span>
            </a>

            <div class="relative z-10 my-auto max-w-xl py-16">
                <p class="mb-5 inline-flex rounded-full border border-gray-900/10 bg-white/35 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.16em] text-gray-800">Make room for what matters</p>
                <h2 class="max-w-lg text-4xl font-semibold leading-[1.08] tracking-tight text-gray-950 xl:text-5xl">A week that works with you.</h2>
                <p class="mt-5 max-w-lg text-base leading-7 text-gray-800/85">Bring routines, real availability, and one-off plans together. Wasaa helps you shape a week that feels achievable.</p>

                <ul class="mt-9 space-y-4">
                    <li class="flex items-start gap-3 text-sm font-medium text-gray-900">
                        <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/60 text-xs font-bold">1</span>
                        Plan around the hours that work for you
                    </li>
                    <li class="flex items-start gap-3 text-sm font-medium text-gray-900">
                        <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/60 text-xs font-bold">2</span>
                        Review suggestions and catch conflicts early
                    </li>
                    <li class="flex items-start gap-3 text-sm font-medium text-gray-900">
                        <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/60 text-xs font-bold">3</span>
                        Track the small routines that add up
                    </li>
                </ul>
            </div>

            <p class="relative z-10 text-xs font-medium text-gray-800/70">A little structure. More breathing room.</p>
        </aside>

        <main class="flex min-h-screen flex-col px-5 py-6 sm:px-8 lg:px-12">
            <header class="mx-auto flex w-full max-w-md items-center justify-between lg:max-w-none">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2 font-bold tracking-tight text-gray-900 lg:invisible">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand text-gray-900"><svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17h16M6.5 14a5.5 5.5 0 0 1 11 0M12 3v2M4.9 6l1.5 1.5M19.1 6l-1.5 1.5"/><path d="M8.5 17v2m7-2v2"/></svg></span>
                    WasaaLifestyle
                </a>
                <div class="flex items-center gap-2">
                    <x-theme-toggle />
                    <a href="{{ route('home') }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-white hover:text-gray-900 sm:text-sm">Back to home</a>
                </div>
            </header>

            <div class="my-auto flex w-full justify-center py-10">
                <section class="wasaa-rise-in w-full max-w-md rounded-[1.75rem] border border-gray-200/80 bg-white p-6 shadow-xl shadow-gray-900/[0.06] sm:p-9">
                    <div class="mb-7">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Wasaa account</p>
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-gray-950">{{ $heading }}</h1>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ $subtitle }}</p>
                    </div>

                    {{ $slot }}
                </section>
            </div>

            <footer class="mx-auto w-full max-w-md pb-2 text-center text-xs text-gray-500 lg:max-w-none">
                Thoughtful planning for real life.
            </footer>
        </main>
    </div>
</body>
</html>
