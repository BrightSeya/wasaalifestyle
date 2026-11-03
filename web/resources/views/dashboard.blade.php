<x-layouts.app title="Dashboard | WasaaLifestyle" heading="Dashboard">
    <div class="space-y-6">
        <section class="dashboard-welcome relative isolate overflow-hidden rounded-[1.75rem] border border-amber-200/70 px-5 py-6 shadow-sm sm:px-7 sm:py-7">
            <div aria-hidden="true" class="pointer-events-none absolute -right-10 -top-24 -z-10 h-64 w-64 rounded-full bg-white/55 blur-2xl"></div>
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="max-w-2xl">
                    <p class="text-[10px] font-bold uppercase tracking-[.17em] text-amber-900/75">A little structure. More breathing room.</p>
                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 sm:text-3xl">Good day, {{ auth()->user()->name }}.</h2>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-gray-700">Your week is taking shape. Here is what is coming up and where you can pick up next.</p>
                </div>
                <a href="{{ route('schedules.create') }}" class="btn-primary shrink-0"><x-icon name="plus" class="h-4 w-4" />Add to my week</a>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-3" aria-label="Weekly overview">
            <a href="{{ route('schedules.week') }}" class="dashboard-stat group rounded-2xl border border-gray-200/80 bg-white p-4 shadow-sm sm:p-5">
                <div class="flex items-center justify-between gap-3"><p class="text-xs font-semibold text-gray-600">Schedule entries</p><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-800"><x-icon name="calendar" class="h-[18px] w-[18px]" /></span></div>
                <p class="mt-3 text-3xl font-semibold tracking-tight text-gray-950" aria-label="{{ $upcomingEntryCount }} schedule entries in the next 7 days">{{ $upcomingEntryCount }}</p>
                <p class="mt-1 text-[11px] text-gray-500">Coming up in the next 7 days</p>
            </a>
            <a href="{{ route('recommendations.index') }}" class="dashboard-stat group rounded-2xl border border-gray-200/80 bg-white p-4 shadow-sm sm:p-5">
                <div class="flex items-center justify-between gap-3"><p class="text-xs font-semibold text-gray-600">Recommendations</p><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-50 text-orange-800"><x-icon name="sparkles" class="h-[18px] w-[18px]" /></span></div>
                <p class="mt-3 text-3xl font-semibold tracking-tight text-gray-950" aria-label="{{ $pendingRecommendationCount }} pending recommendations">{{ $pendingRecommendationCount }}</p>
                <p class="mt-1 text-[11px] text-gray-500">Suggestions ready for your review</p>
            </a>
            <a href="{{ route('notifications.index') }}" class="dashboard-stat group rounded-2xl border border-gray-200/80 bg-white p-4 shadow-sm sm:p-5">
                <div class="flex items-center justify-between gap-3"><p class="text-xs font-semibold text-gray-600">Notifications</p><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-700"><x-icon name="bell" class="h-[18px] w-[18px]" /></span></div>
                <p class="mt-3 text-3xl font-semibold tracking-tight text-gray-950" aria-label="{{ $unreadNotificationCount }} unread notifications">{{ $unreadNotificationCount }}</p>
                <p class="mt-1 text-[11px] text-gray-500">Unread updates</p>
            </a>
        </section>

        <section class="app-card rounded-2xl border border-gray-200/80 bg-white p-5 sm:p-6" aria-labelledby="upcoming-schedule-heading">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-[10px] font-bold uppercase tracking-[.15em] text-amber-800">Plan ahead</p><h2 id="upcoming-schedule-heading" class="mt-1 text-base font-semibold tracking-tight text-gray-950">Today and the next 6 days</h2></div>
                <a href="{{ route('schedules.week') }}" class="text-xs font-semibold text-amber-900 underline decoration-amber-300 underline-offset-4 hover:text-amber-700">View full schedule</a>
            </div>

            @if ($upcomingEntryCount === 0)
                <div class="rounded-2xl border border-dashed border-amber-200 bg-amber-50/55 px-5 py-8 text-center">
                    <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-amber-800 shadow-sm"><x-icon name="calendar" class="h-5 w-5" /></span>
                    <p class="mt-3 text-sm font-semibold text-gray-900">Your week has room to take shape.</p>
                    <p class="mt-1 text-xs text-gray-600">Add a task or activity and start building a rhythm that works for you.</p>
                    <a href="{{ route('schedules.create') }}" class="btn-primary mt-4"><x-icon name="plus" class="h-4 w-4" />Create a task</a>
                </div>
            @else
                <div class="space-y-5">
                    @foreach ($scheduleDays as $day)
                        @php($dayEntries = $entriesByDate->get($day->toDateString(), collect()))
                        @if ($dayEntries->isNotEmpty())
                            <div>
                                <h3 class="mb-2 flex items-center gap-2 text-[10px] font-bold uppercase tracking-[.14em] text-gray-500"><span class="h-1.5 w-1.5 rounded-full {{ $day->isToday() ? 'bg-amber-500' : 'bg-gray-300' }}"></span>{{ $day->isToday() ? 'Today' : $day->format('l, M j') }}</h3>
                                <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-100 px-3 sm:px-4">
                                    @foreach ($dayEntries as $entry)
                                        <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                                            <div class="min-w-0"><p class="truncate text-sm font-semibold text-gray-900">{{ $entry->schedule->title }}</p><p class="mt-1 text-[11px] text-gray-500">{{ ucfirst($entry->schedule->category->value) }} @if ($entry->schedule->is_recurring) <span class="px-1 text-gray-300">·</span>{{ ucfirst($entry->schedule->recurrence_rule) }} @endif</p></div>
                                            <time class="rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs font-medium text-gray-700">{{ $entry->start->format('g:i A') }}–{{ $entry->end->format('g:i A') }}</time>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        <section class="app-card rounded-2xl border border-gray-200/80 bg-white p-5 sm:p-6" aria-labelledby="completion-heading">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><p class="text-[10px] font-bold uppercase tracking-[.15em] text-amber-800">Consistency over time</p><h2 id="completion-heading" class="mt-1 text-base font-semibold tracking-tight text-gray-950">Activity completion</h2></div>
                <a href="{{ route('activities.index') }}" class="text-xs font-semibold text-amber-900 underline decoration-amber-300 underline-offset-4 hover:text-amber-700">Open activities</a>
            </div>
            @unless ($hasActivityHistory)
                <div class="mb-4 flex items-start gap-3 rounded-xl bg-gray-50 p-3.5"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-amber-800 shadow-sm"><x-icon name="activities" class="h-4 w-4" /></span><p class="pt-1 text-xs leading-5 text-gray-600">Log an activity to start seeing your progress here.</p></div>
            @endunless
            <canvas id="dashboard-completion-chart" data-completion-chart data-chart="{{ json_encode($completionByDay) }}" height="80"></canvas>
            <p class="mt-2 text-[10px] text-gray-400">Completed activities over the past 7 days</p>
        </section>
    </div>
</x-layouts.app>
