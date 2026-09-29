@php
    $hourHeight = 48; // pixels per hour
    $colors = [
        'work' => 'border-blue-300 bg-blue-50',
        'exercise' => 'border-green-300 bg-green-50',
        'leisure' => 'border-purple-300 bg-purple-50',
        'errand' => 'border-amber-300 bg-amber-50',
        'social' => 'border-pink-300 bg-pink-50',
        'rest' => 'border-slate-300 bg-slate-100',
    ];
    $gridHeight = ($endHour - $startHour) * $hourHeight;
@endphp

<x-layouts.app title="Schedule | WasaaLifestyle" heading="Week of {{ $weekStart->format('M j, Y') }}">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex items-center gap-2 text-sm" aria-label="Week navigation">
            <a href="{{ route('schedules.week', ['date' => $weekStart->copy()->subWeek()->format('Y-m-d')]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-1">&lsaquo; Previous</a>
            <a href="{{ route('schedules.week') }}" class="rounded-md border border-gray-300 bg-white px-3 py-1">This week</a>
            <a href="{{ route('schedules.week', ['date' => $weekStart->copy()->addWeek()->format('Y-m-d')]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-1">Next &rsaquo;</a>
        </nav>

        <div class="flex items-center gap-3 text-sm">
            <a href="{{ route('schedules.index') }}" class="text-gray-600 underline">List view</a>
            <a href="{{ route('schedules.create') }}" class="rounded-full bg-gray-900 px-4 py-2 font-semibold text-white">+ New Task</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
        <div class="grid min-w-[800px]" style="grid-template-columns: 4rem repeat(7, minmax(0, 1fr));">
            <div class="border-b border-gray-100"></div>
            @foreach ($days as $day)
                <a href="{{ route('schedules.create', ['date' => $day->format('Y-m-d')]) }}"
                   title="Add a task on {{ $day->format('l') }}"
                   @class([
                       'border-b border-l border-gray-100 px-2 py-3 text-center text-sm font-semibold hover:bg-gray-50',
                       'bg-brand/20' => $day->isToday(),
                   ])>
                    {{ $day->format('D j') }}
                </a>
            @endforeach

            <div class="relative" style="height: {{ $gridHeight }}px;">
                @for ($hour = $startHour; $hour < $endHour; $hour++)
                    <span class="absolute right-2 -translate-y-1/2 text-[10px] text-gray-400" style="top: {{ ($hour - $startHour) * $hourHeight }}px;">
                        {{ \Illuminate\Support\Carbon::createFromTime($hour)->format('g A') }}
                    </span>
                @endfor
            </div>

            @foreach ($days as $day)
                <div class="relative border-l border-gray-100" style="height: {{ $gridHeight }}px;" data-day="{{ $day->format('Y-m-d') }}">
                    @for ($hour = $startHour; $hour < $endHour; $hour++)
                        <div class="absolute inset-x-0 border-t border-gray-100" style="top: {{ ($hour - $startHour) * $hourHeight }}px;"></div>
                    @endfor

                    @foreach ($entriesByDay->get($day->format('Y-m-d'), []) as $entry)
                        @php
                            $minutesFromTop = ($entry->start_datetime->hour - $startHour) * 60 + $entry->start_datetime->minute;
                            $duration = abs($entry->start_datetime->diffInMinutes($entry->end_datetime));
                        @endphp
                        <a href="{{ route('schedules.edit', $entry) }}"
                           class="absolute inset-x-1 overflow-hidden rounded-md border px-2 py-1 text-xs hover:shadow {{ $colors[$entry->category->value] }}"
                           style="top: {{ $minutesFromTop / 60 * $hourHeight }}px; height: {{ max(20, $duration / 60 * $hourHeight) }}px;"
                           title="{{ $entry->title }} ({{ $entry->start_datetime->format('g:i A') }} - {{ $entry->end_datetime->format('g:i A') }})">
                            <span class="block truncate font-semibold">{{ $entry->title }}</span>
                            <span class="block truncate text-gray-500">{{ ucfirst($entry->category->value) }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</x-layouts.app>
