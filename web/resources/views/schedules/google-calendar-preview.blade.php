<x-layouts.app title="Review Calendar Import | WasaaLifestyle" heading="Review Google Calendar Import">
    @php
        $duplicateIds = array_fill_keys($preview['duplicate_ids'], true);
        $newEventCount = count($preview['events']) - count($preview['duplicate_ids']);
        $conflictedCount = count($preview['conflicts']);
    @endphp

    <div class="space-y-6">
        @if ($updatedSinceReview)
            <p role="alert" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Your schedule changed while this import was open. Review the updated duplicate and conflict summary, then confirm again.
            </p>
        @endif

        @if ($preview['truncated'])
            <p role="status" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                This range contains more than 500 events. The first 500 are shown; use a shorter date range to import the remaining events.
            </p>
        @endif

        <section class="rounded-xl bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold">Import summary</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-4">
                <div><dt class="text-xs text-gray-500">Date range</dt><dd class="mt-1 text-sm">{{ $preview['date_from'] }} through {{ $preview['date_to'] }}</dd></div>
                <div><dt class="text-xs text-gray-500">New events</dt><dd class="mt-1 text-sm font-semibold">{{ $newEventCount }}</dd></div>
                <div><dt class="text-xs text-gray-500">Already imported</dt><dd class="mt-1 text-sm">{{ count($preview['duplicate_ids']) }}</dd></div>
                <div><dt class="text-xs text-gray-500">Unsupported</dt><dd class="mt-1 text-sm">{{ count($preview['unsupported']) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">New schedule entries use the {{ ucfirst($preview['category']) }} category and Medium priority.</p>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold">Events to review</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[700px] text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500">
                        <tr><th class="py-3 pr-4">Event</th><th class="py-3 pr-4">Start</th><th class="py-3 pr-4">End</th><th class="py-3 pr-4">Time zone</th><th class="py-3">Review</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($preview['events'] as $event)
                            <tr>
                                <th scope="row" class="py-3 pr-4 font-medium">{{ $event['title'] }}</th>
                                <td class="py-3 pr-4">{{ $event['display_start'] }}</td>
                                <td class="py-3 pr-4">{{ $event['display_end'] }}</td>
                                <td class="py-3 pr-4">{{ $event['timezone'] }}</td>
                                <td class="py-3">
                                    @if (isset($duplicateIds[$event['google_event_id']]))
                                        <span class="text-gray-500">Already imported</span>
                                    @elseif (isset($preview['conflicts'][$event['google_event_id']]))
                                        <span class="font-medium text-amber-800">{{ count($preview['conflicts'][$event['google_event_id']]) }} conflict(s)</span>
                                    @else
                                        <span class="text-green-800">Ready</span>
                                    @endif
                                </td>
                            </tr>
                            @if (isset($preview['conflicts'][$event['google_event_id']]))
                                <tr class="bg-amber-50">
                                    <td colspan="5" class="px-4 py-2 text-xs text-amber-900">
                                        <span class="font-semibold">Schedule overlap:</span>
                                        {{ collect($preview['conflicts'][$event['google_event_id']])->pluck('message')->implode('; ') }}
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-gray-500">No supported timed events were found in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if ($conflictedCount > 0)
            <p class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $conflictedCount }} event(s) overlap existing schedule entries. They will be imported only after you confirm this review.
            </p>
        @else
            <p class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">No conflicts with your existing schedule were found.</p>
        @endif

        @if ($preview['unsupported'] !== [])
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5" aria-labelledby="unsupported-heading">
                <h2 id="unsupported-heading" class="text-sm font-semibold text-amber-950">Events that will not be imported</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                    @foreach ($preview['unsupported'] as $event)
                        <li><span class="font-medium">{{ $event['title'] }}:</span> {{ $event['reason'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('schedules.google-calendar.import') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm">Change date range</a>
            <form method="POST" action="{{ route('schedules.google-calendar.confirm') }}">
                @csrf
                <input type="hidden" name="preview_id" value="{{ $preview['preview_id'] }}">
                <button type="submit" @disabled($newEventCount === 0) class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50">
                    Confirm and import {{ $newEventCount }} event(s)
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
