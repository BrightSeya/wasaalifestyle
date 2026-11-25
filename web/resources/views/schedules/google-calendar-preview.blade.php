<x-layouts.app title="Review Calendar Import | WasaaLifestyle" heading="Review Google Calendar Import">
    @php
        $duplicateIds = array_fill_keys($preview['duplicate_ids'], true);
        $newEventCount = count($preview['events']) - count($preview['duplicate_ids']);
        $conflictedCount = count($preview['conflicts']);
        $categoryErrors = $categoryErrors ?? [];
        $selectedCategories = $selectedCategories ?? [];
    @endphp

    <div class="space-y-6">
        @if ($updatedSinceReview)
            <p role="alert" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Your schedule changed while this import was open. Review the updated duplicate and conflict summary, then confirm again.
            </p>
        @endif

        @if ($preview['truncated'])
            <p role="status" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                The preview is limited to 500 calendar items. The range may contain more; use a shorter date range to see additional items.
            </p>
        @endif

        <section class="rounded-xl bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold">Import summary</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-4">
                <div><dt class="text-xs text-gray-500">Date range</dt><dd class="mt-1 text-sm">{{ $preview['date_from'] }} through {{ $preview['date_to'] }}</dd></div>
                <div><dt class="text-xs text-gray-500">New items</dt><dd class="mt-1 text-sm font-semibold">{{ $newEventCount }}</dd></div>
                <div><dt class="text-xs text-gray-500">Already imported</dt><dd class="mt-1 text-sm">{{ count($preview['duplicate_ids']) }}</dd></div>
                <div><dt class="text-xs text-gray-500">Unsupported</dt><dd class="mt-1 text-sm">{{ count($preview['unsupported']) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">Choose a category for each new event or task below. Imported entries use Medium priority.</p>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold">Items to review</h2>
            @if (! empty($categoryErrors['categories']))
                <p role="alert" class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $categoryErrors['categories'][0] }}</p>
            @endif
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[700px] text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500">
                        <tr><th class="py-3 pr-4">Item</th><th class="py-3 pr-4">Start</th><th class="py-3 pr-4">End</th><th class="py-3 pr-4">Time zone</th><th class="py-3 pr-4">Category</th><th class="py-3">Review</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($preview['events'] as $index => $event)
                            <tr>
                                <th scope="row" class="py-3 pr-4 font-medium">{{ $event['title'] }}</th>
                                <td class="py-3 pr-4">{{ $event['source_type'] }} · {{ $event['display_start'] }}</td>
                                <td class="py-3 pr-4">{{ $event['display_end'] }}</td>
                                <td class="py-3 pr-4">{{ $event['timezone'] }}</td>
                                <td class="py-3 pr-4">
                                    @if (isset($duplicateIds[$event['google_event_id']]))
                                        <span class="text-gray-500">Already imported</span>
                                    @else
                                        <label class="sr-only" for="category-{{ $index }}">Category for {{ $event['title'] }}</label>
                                        <select id="category-{{ $index }}" name="categories[{{ $index }}]" form="calendar-import-confirm" required class="w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm">
                                            <option value="" disabled @selected(empty($selectedCategories[$index] ?? null))>Choose a category</option>
                                            @foreach ($categories as $category)
                                                <option value="{{ $category->value }}" @selected(($selectedCategories[$index] ?? '') === $category->value)>{{ ucfirst($category->value) }}</option>
                                            @endforeach
                                        </select>
                                        @if (! empty($categoryErrors["categories.{$index}"]))
                                            <p class="mt-1 text-xs text-red-600">{{ $categoryErrors["categories.{$index}"][0] }}</p>
                                        @endif
                                    @endif
                                </td>
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
                                    <td colspan="6" class="px-4 py-2 text-xs text-amber-900">
                                        <span class="font-semibold">Schedule overlap:</span>
                                        {{ collect($preview['conflicts'][$event['google_event_id']])->pluck('message')->implode('; ') }}
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-gray-500">No supported calendar items were found in this range.</td></tr>
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
                <h2 id="unsupported-heading" class="text-sm font-semibold text-amber-950">Items that will not be imported</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
                    @foreach ($preview['unsupported'] as $event)
                        <li><span class="font-medium">{{ $event['title'] }}:</span> {{ $event['reason'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('schedules.google-calendar.import') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm">Change date range</a>
            <form id="calendar-import-confirm" method="POST" action="{{ route('schedules.google-calendar.confirm') }}">
                @csrf
                <input type="hidden" name="preview_id" value="{{ $preview['preview_id'] }}">
                <button type="submit" @disabled($newEventCount === 0) class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50">
                    Confirm and import {{ $newEventCount }} item(s)
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
