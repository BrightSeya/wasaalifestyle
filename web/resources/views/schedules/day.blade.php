<x-layouts.app title="Schedule | WasaaLifestyle" heading="{{ $day->isToday() ? 'Today' : $day->format('l') }}, {{ $day->format('M j, Y') }}">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex items-center gap-2 text-sm" aria-label="Day navigation">
            <a href="{{ route('schedules.day', ['date' => $day->copy()->subDay()->format('Y-m-d')]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-1">&lsaquo; Previous</a>
            <a href="{{ route('schedules.day') }}" class="rounded-md border border-gray-300 bg-white px-3 py-1">Today</a>
            <a href="{{ route('schedules.day', ['date' => $day->copy()->addDay()->format('Y-m-d')]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-1">Next &rsaquo;</a>
        </nav>

        <div class="flex items-center gap-3 text-sm">
            <a href="{{ route('schedules.week', ['date' => $day->format('Y-m-d')]) }}" class="text-gray-600 underline">Week view</a>
            <a href="{{ route('schedules.create', ['date' => $day->format('Y-m-d')]) }}" class="rounded-full bg-gray-900 px-4 py-2 font-semibold text-white">+ New Task</a>
        </div>
    </div>

    @if ($entries->isEmpty())
        <p class="rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm">Nothing scheduled for this day.</p>
    @else
        <ol class="divide-y divide-gray-100 rounded-xl bg-white shadow-sm">
            @foreach ($entries as $entry)
                <li>
                    <a href="{{ route('schedules.edit', $entry) }}" class="flex items-center gap-4 px-4 py-3 hover:bg-gray-50">
                        <span class="w-36 shrink-0 text-sm text-gray-500">
                            {{ $entry->start_datetime->format('g:i A') }} - {{ $entry->end_datetime->format('g:i A') }}
                        </span>
                        <span class="flex-1 font-medium">{{ $entry->title }}</span>
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ ucfirst($entry->category->value) }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
    @endif
</x-layouts.app>
