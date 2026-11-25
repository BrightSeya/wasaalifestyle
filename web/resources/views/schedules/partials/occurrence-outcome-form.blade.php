@php
    $outcomeKey = $occurrence->schedule->id.'|'.$occurrence->start->format('Y-m-d H:i:s');
    $savedOutcome = $occurrenceOutcomes->get($outcomeKey);
    $outcomeButtons = [
        'completed' => ['Completed', 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:border-emerald-400 hover:bg-emerald-100', 'border-emerald-500 bg-emerald-100 ring-2 ring-emerald-200'],
        'missed' => ['Missed', 'border-rose-200 bg-rose-50 text-rose-800 hover:border-rose-400 hover:bg-rose-100', 'border-rose-500 bg-rose-100 ring-2 ring-rose-200'],
        'skipped' => ['Skipped', 'border-amber-200 bg-amber-50 text-amber-800 hover:border-amber-400 hover:bg-amber-100', 'border-amber-500 bg-amber-100 ring-2 ring-amber-200'],
    ];
@endphp

@if ($savedOutcome?->outcome === 'cancelled')
    <p class="mt-2 rounded-lg bg-gray-100 px-2.5 py-2 text-xs font-semibold text-gray-600">This occurrence is cancelled.</p>
@elseif ($occurrence->end->lte(now()))
    <form method="POST" action="{{ route('schedules.outcomes.store', $occurrence->schedule) }}" class="mt-3">
        @csrf
        <input type="hidden" name="occurrence_start" value="{{ $occurrence->start->format('Y-m-d H:i:s') }}">
        <p class="mb-1.5 text-[11px] font-semibold text-gray-600">{{ $savedOutcome ? 'Update outcome' : 'Record outcome' }}</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach ($outcomeButtons as $outcome => [$label, $defaultClasses, $selectedClasses])
                <button type="submit" name="outcome" value="{{ $outcome }}" aria-pressed="{{ $savedOutcome?->outcome === $outcome ? 'true' : 'false' }}"
                        class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 {{ $savedOutcome?->outcome === $outcome ? $selectedClasses : $defaultClasses }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        @if ($savedOutcome)
            <p class="mt-1.5 text-[10px] text-gray-500">Saved {{ $savedOutcome->recorded_at->diffForHumans() }}</p>
        @endif
    </form>
@elseif (!$savedOutcome)
    <form method="POST" action="{{ route('schedules.outcomes.store', $occurrence->schedule) }}" class="mt-3" onsubmit="return confirm('{{ $occurrence->schedule->is_recurring ? 'Cancel this occurrence? The repeating task and its other occurrences will remain scheduled.' : 'Cancel this task? It will be marked as cancelled in your schedule.' }}');">
        @csrf
        <input type="hidden" name="occurrence_start" value="{{ $occurrence->start->format('Y-m-d H:i:s') }}">
        <input type="hidden" name="outcome" value="cancelled">
        <button type="submit" class="rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-300">Cancel this occurrence</button>
    </form>
@endif
