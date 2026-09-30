<x-layouts.app title="Activities | WasaaLifestyle" heading="Lifestyle Activities">
    <div class="mb-4 flex justify-end">
        <a href="{{ route('activities.create') }}" class="rounded-full bg-gray-900 px-4 py-2 text-sm font-semibold text-white">+ Add Activity</a>
    </div>

    @if ($activities->isEmpty())
        <p class="rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm">No activities yet. Add your first one.</p>
    @else
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-100 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Activity Name</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Preferred Time</th>
                        <th class="px-4 py-3">Frequency</th>
                        <th class="px-4 py-3">Duration</th>
                        <th class="px-4 py-3">Streak</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($activities as $activity)
                        <tr class="cursor-pointer hover:bg-gray-50" onclick="window.location='{{ route('activities.edit', $activity) }}'">
                            <td class="px-4 py-3 font-medium">{{ $activity->name }}</td>
                            <td class="px-4 py-3"><span class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ ucfirst($activity->category->value) }}</span></td>
                            <td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($activity->preferred_time_of_day)->format('g:i A') }}</td>
                            <td class="px-4 py-3">{{ $activity->frequency }}x/week</td>
                            <td class="px-4 py-3">{{ $activity->duration_minutes }} min</td>
                            <td class="px-4 py-3 text-gray-400">&mdash;</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
