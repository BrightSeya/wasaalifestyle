@php
    $outcomeKey = $occurrence->schedule->id.'|'.$occurrence->start->format('Y-m-d H:i:s');
    $savedOutcome = $occurrenceOutcomes->get($outcomeKey);
    $outcomeSelectId = 'occurrence-outcome-'.$occurrence->schedule->id.'-'.$occurrence->start->format('YmdHi');
@endphp

@if ($occurrence->end->lte(now()) || $savedOutcome?->outcome === 'cancelled')
    <form method="POST" action="{{ route('schedules.outcomes.store', $occurrence->schedule) }}" class="mt-2 flex flex-wrap items-center gap-2">
        @csrf
        <input type="hidden" name="occurrence_start" value="{{ $occurrence->start->format('Y-m-d H:i:s') }}">
        <label for="{{ $outcomeSelectId }}" class="sr-only">Outcome for {{ $occurrence->schedule->title }} on {{ $occurrence->start->format('M j, Y') }}</label>
        <select id="{{ $outcomeSelectId }}" name="outcome" class="rounded-lg border-gray-300 px-2 py-1 text-xs text-gray-700 focus:border-brand focus:ring-brand" required>
            @if ($occurrence->end->lte(now()))
                <option value="completed" @selected($savedOutcome?->outcome === 'completed')>Completed</option>
                <option value="missed" @selected($savedOutcome?->outcome === 'missed')>Missed</option>
                <option value="skipped" @selected($savedOutcome?->outcome === 'skipped')>Skipped</option>
            @endif
            <option value="cancelled" @selected($savedOutcome?->outcome === 'cancelled')>Cancelled</option>
        </select>
        <button type="submit" class="rounded-lg border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 transition hover:border-brand hover:text-gray-950">
            {{ $savedOutcome ? 'Update outcome' : 'Record outcome' }}
        </button>
        @if ($savedOutcome)
            <span class="text-[10px] text-gray-500">Recorded {{ $savedOutcome->recorded_at->diffForHumans() }}</span>
        @endif
    </form>
@elseif (!$savedOutcome)
    <form method="POST" action="{{ route('schedules.outcomes.store', $occurrence->schedule) }}" class="mt-2">
        @csrf
        <input type="hidden" name="occurrence_start" value="{{ $occurrence->start->format('Y-m-d H:i:s') }}">
        <input type="hidden" name="outcome" value="cancelled">
        <button type="submit" class="text-xs font-semibold text-gray-500 underline hover:text-red-700">Cancel this occurrence</button>
    </form>
@endif
