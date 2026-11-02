<x-layouts.app title="Dashboard | WasaaLifestyle" heading="Good day, {{ auth()->user()->name }}">
    <div class="grid gap-4 sm:grid-cols-3">
        <a href="{{ route('schedules.week') }}" class="rounded-xl bg-white p-5 shadow-sm hover:bg-gray-50">
            <p class="text-sm text-gray-500">Schedule entries · next 7 days</p>
            <p class="mt-2 text-2xl font-semibold" aria-label="{{ $upcomingEntryCount }} schedule entries in the next 7 days">
                {{ $upcomingEntryCount }}
            </p>
        </a>
        <a href="{{ route('recommendations.index') }}" class="rounded-xl bg-white p-5 shadow-sm hover:bg-gray-50">
            <p class="text-sm text-gray-500">Pending recommendations</p>
            <p class="mt-2 text-2xl font-semibold" aria-label="{{ $pendingRecommendationCount }} pending recommendations">
                {{ $pendingRecommendationCount }}
            </p>
        </a>
        <a href="{{ route('notifications.index') }}" class="rounded-xl bg-white p-5 shadow-sm hover:bg-gray-50">
            <p class="text-sm text-gray-500">Unread notifications</p>
            <p class="mt-2 text-2xl font-semibold" aria-label="{{ $unreadNotificationCount }} unread notifications">
                {{ $unreadNotificationCount }}
            </p>
        </a>
    </div>

    <section class="mt-6 rounded-xl bg-white p-5 shadow-sm" aria-labelledby="upcoming-schedule-heading">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 id="upcoming-schedule-heading" class="text-sm font-semibold text-gray-700">Today and the next 6 days</h2>
            <a href="{{ route('schedules.create') }}" class="text-sm font-semibold text-gray-700 underline">+ New task</a>
        </div>

        @if ($upcomingEntryCount === 0)
            <p class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">No scheduled entries in the next seven days.</p>
        @else
            <div class="space-y-5">
                @foreach ($scheduleDays as $day)
                    @php($dayEntries = $entriesByDate->get($day->toDateString(), collect()))
                    @if ($dayEntries->isNotEmpty())
                        <div>
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ $day->isToday() ? 'Today' : $day->format('l, M j') }}
                            </h3>
                            <ul class="divide-y divide-gray-100">
                                @foreach ($dayEntries as $entry)
                                    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                                        <div>
                                            <p class="text-sm font-medium">{{ $entry->schedule->title }}</p>
                                            <p class="mt-1 text-xs text-gray-500">
                                                {{ ucfirst($entry->schedule->category->value) }}
                                                @if ($entry->schedule->is_recurring)
                                                    · {{ ucfirst($entry->schedule->recurrence_rule) }}
                                                @endif
                                            </p>
                                        </div>
                                        <time class="text-sm text-gray-600">
                                            {{ $entry->start->format('g:i A') }}–{{ $entry->end->format('g:i A') }}
                                        </time>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-6 rounded-xl bg-white p-5 shadow-sm" aria-labelledby="completion-heading">
        <h2 id="completion-heading" class="mb-3 text-sm font-semibold text-gray-700">Activity completion · past 7 days</h2>
        @unless ($hasActivityHistory)
            <p class="mb-3 text-sm text-gray-500">No activity history yet. Log an activity to start your progress chart.</p>
        @endunless
        <canvas id="dashboard-completion-chart" data-completion-chart data-chart="{{ json_encode($completionByDay) }}" height="80"></canvas>
        <a href="{{ route('activities.index') }}" class="mt-3 inline-block text-xs font-semibold text-gray-600 underline">View activities</a>
    </section>
</x-layouts.app>
