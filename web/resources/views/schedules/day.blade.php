<x-layouts.app title="Schedule | WasaaLifestyle" heading="{{ $day->isToday() ? 'Today' : $day->format('l') }}, {{ $day->format('M j, Y') }}">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex items-center gap-2 text-sm" aria-label="Day navigation">
            <a href="{{ route('schedules.day', ['date' => $day->copy()->subDay()->format('Y-m-d')]) }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs"><x-icon name="arrow-right" class="h-4 w-4 rotate-180" />Previous</a>
            <a href="{{ route('schedules.day') }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs">Today</a>
            <a href="{{ route('schedules.day', ['date' => $day->copy()->addDay()->format('Y-m-d')]) }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs">Next<x-icon name="arrow-right" class="h-4 w-4" /></a>
        </nav>

        <div class="flex flex-wrap items-center justify-end gap-2 text-sm">
            <a href="{{ route('schedules.week', ['date' => $day->format('Y-m-d')]) }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-white hover:text-gray-950">Week view</a>
            <a href="{{ route('schedules.google-calendar.import') }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs">Import calendar</a>
            <a href="{{ route('schedules.create', ['date' => $day->format('Y-m-d')]) }}" class="btn-primary !rounded-lg !px-3 !py-2 !text-xs"><x-icon name="plus" class="h-4 w-4" />New task</a>
        </div>
    </div>

    @if ($entries->isEmpty())
        <p class="rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm">Nothing scheduled for this day.</p>
    @else
        <ol class="divide-y divide-gray-100 rounded-xl bg-white shadow-sm">
            @foreach ($entries as $entry)
                <li>
                    <a href="{{ route('schedules.edit', $entry->schedule) }}" class="flex items-center gap-4 px-4 py-3 hover:bg-gray-50">
                        <span class="w-36 shrink-0 text-sm text-gray-500">
                            {{ $entry->start->format('g:i A') }} - {{ $entry->end->format('g:i A') }}
                            @if ($entry->schedule->timezone)<span class="block text-[10px]">Google source zone: {{ $entry->schedule->timezone }}</span>@endif
                        </span>
                        <span class="flex-1 font-medium">{{ $entry->schedule->title }}</span>
                        @if ($entry->schedule->is_recurring)
                            <span class="text-xs text-gray-500">{{ ucfirst($entry->schedule->recurrence_rule) }}</span>
                        @endif
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ ucfirst($entry->schedule->category->value) }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
    @endif
</x-layouts.app>
