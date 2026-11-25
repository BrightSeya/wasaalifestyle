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
            <a href="{{ route('schedules.week', ['date' => $weekStart->copy()->subWeek()->format('Y-m-d')]) }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs"><x-icon name="arrow-right" class="h-4 w-4 rotate-180" />Previous</a>
            <a href="{{ route('schedules.week') }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs">This week</a>
            <a href="{{ route('schedules.week', ['date' => $weekStart->copy()->addWeek()->format('Y-m-d')]) }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs">Next<x-icon name="arrow-right" class="h-4 w-4" /></a>
        </nav>

        <div class="flex flex-wrap items-center justify-end gap-2 text-sm">
            <a href="{{ route('schedules.day') }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-white hover:text-gray-950">Day view</a>
            <a href="{{ route('schedules.index') }}" class="rounded-lg px-3 py-2 text-xs font-semibold text-gray-600 hover:bg-white hover:text-gray-950">List view</a>
            <a href="{{ route('schedules.google-calendar.import') }}" class="btn-secondary !rounded-lg !px-3 !py-2 !text-xs">Import calendar</a>
            <a href="{{ route('schedules.create') }}" class="btn-primary !rounded-lg !px-3 !py-2 !text-xs"><x-icon name="plus" class="h-4 w-4" />New task</a>
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

            <div class="border-b border-gray-100 px-2 py-2 text-[10px] font-semibold text-gray-500">All day</div>
            @foreach ($days as $day)
                <div class="border-b border-l border-gray-100 p-1" data-all-day="{{ $day->format('Y-m-d') }}">
                    @foreach ($allDayEntriesByDay->get($day->format('Y-m-d'), []) as $entry)
                        <a href="{{ route('schedules.edit', $entry->schedule) }}" class="mb-1 block truncate rounded border px-1.5 py-1 text-[10px] font-medium {{ $colors[$entry->schedule->category->value] }}" title="{{ $entry->schedule->title }} — All day">
                            {{ $entry->schedule->title }}@if ($entry->schedule->status === 'completed') <span class="font-semibold text-green-800">· Completed</span>@endif
                        </a>
                    @endforeach
                </div>
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
                            $minutesFromTop = ($entry->start->hour - $startHour) * 60 + $entry->start->minute;
                            $duration = abs($entry->start->diffInMinutes($entry->end));
                        @endphp
                        @php
                            $outcomeKey = $entry->schedule->id.'|'.$entry->start->format('Y-m-d H:i:s');
                            $savedOutcome = $occurrenceOutcomes->get($outcomeKey);
                        @endphp
                        <div
                           data-occurrence-card
                           @class(['absolute inset-x-1 rounded-md border px-2 py-1 text-xs hover:shadow', $colors[$entry->schedule->category->value], 'opacity-60 line-through' => $savedOutcome?->outcome === 'cancelled'])
                           style="top: {{ $minutesFromTop / 60 * $hourHeight }}px; height: {{ max(36, $duration / 60 * $hourHeight) }}px;"
                           title="{{ $entry->schedule->title }} ({{ $entry->start->format('g:i A') }} - {{ $entry->end->format('g:i A') }})@if ($entry->schedule->timezone) — Google source zone: {{ $entry->schedule->timezone }}@endif">
                            <button type="button" data-occurrence-toggle aria-expanded="false"
                                    aria-label="Show details for {{ $entry->schedule->title }} on {{ $entry->start->format('l, F j') }}"
                                    class="block w-full cursor-pointer text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                                <span class="block truncate font-semibold">{{ $entry->schedule->title }}</span>
                                <span class="block truncate text-gray-500">{{ ucfirst($entry->schedule->category->value) }} · {{ $entry->start->format('g:i A') }}</span>
                            </button>

                            <div data-occurrence-details hidden class="absolute left-0 top-0 z-50 w-56 max-w-[calc(100vw-3rem)] rounded-xl border border-gray-200 bg-white p-3 text-left text-gray-800 shadow-xl">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="break-words font-semibold">{{ $entry->schedule->title }}</p>
                                        <p class="mt-1 text-xs text-gray-600">{{ $entry->start->format('l, M j') }} · {{ $entry->start->format('g:i A') }}–{{ $entry->end->format('g:i A') }}</p>
                                        <p class="text-xs text-gray-500">{{ ucfirst($entry->schedule->category->value) }}@if ($entry->schedule->is_recurring) · Repeats {{ $entry->schedule->recurrence_rule }}@endif</p>
                                    </div>
                                    <button type="button" data-occurrence-close class="rounded px-1 text-lg leading-none text-gray-500 hover:bg-gray-100" aria-label="Close task details">&times;</button>
                                </div>
                                <a href="{{ route('schedules.edit', $entry->schedule) }}" class="mt-2 inline-block text-xs font-semibold text-gray-700 underline">Edit task</a>
                                @if ($savedOutcome?->outcome === 'cancelled')
                                    <p class="mt-2 text-xs font-semibold text-red-700">This occurrence is cancelled.</p>
                                @else
                                    @include('schedules.partials.occurrence-outcome-form', ['occurrence' => $entry])
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <script>
        (() => {
            const cards = [...document.querySelectorAll('[data-occurrence-card]')];
            let pinnedCard = null;

            const setOpen = (card, open) => {
                const details = card.querySelector('[data-occurrence-details]');
                const toggle = card.querySelector('[data-occurrence-toggle]');
                details.hidden = !open;
                toggle.setAttribute('aria-expanded', String(open));
                card.classList.toggle('z-30', open);
            };

            cards.forEach(card => {
                const toggle = card.querySelector('[data-occurrence-toggle]');
                const close = card.querySelector('[data-occurrence-close]');
                card.addEventListener('mouseenter', () => {
                    if (!pinnedCard) setOpen(card, true);
                });
                card.addEventListener('mouseleave', event => {
                    if (!pinnedCard && !card.contains(event.relatedTarget)) setOpen(card, false);
                });
                card.addEventListener('focusin', () => {
                    if (!pinnedCard) setOpen(card, true);
                });
                card.addEventListener('focusout', event => {
                    if (!pinnedCard && !card.contains(event.relatedTarget)) setOpen(card, false);
                });
                toggle.addEventListener('click', () => {
                    if (pinnedCard === card) {
                        pinnedCard = null;
                        setOpen(card, false);
                    } else {
                        if (pinnedCard) setOpen(pinnedCard, false);
                        pinnedCard = card;
                        setOpen(card, true);
                    }
                });
                close.addEventListener('click', () => {
                    if (pinnedCard === card) pinnedCard = null;
                    toggle.focus();
                    setOpen(card, false);
                });
            });

            document.addEventListener('click', event => {
                if (pinnedCard && !pinnedCard.contains(event.target)) {
                    setOpen(pinnedCard, false);
                    pinnedCard = null;
                }
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && pinnedCard) {
                    const card = pinnedCard;
                    pinnedCard = null;
                    card.querySelector('[data-occurrence-toggle]').focus();
                    setOpen(card, false);
                }
            });
        })();
    </script>
</x-layouts.app>
