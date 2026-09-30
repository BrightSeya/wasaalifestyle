<x-layouts.app title="Activities | WasaaLifestyle" heading="Lifestyle Activities">
    @if (session('error'))
        <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

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
                        <th class="px-4 py-3">Recent</th>
                        <th class="px-4 py-3">Log Today</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($activities as $activity)
                        @php
                            $today = $activity->logs->first(fn ($log) => $log->scheduled_for->isToday());
                            $dotColor = ['completed' => 'bg-green-500', 'missed' => 'bg-red-400', 'skipped' => 'bg-gray-300'];
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium">
                                <a href="{{ route('activities.edit', $activity) }}">{{ $activity->name }}</a>
                            </td>
                            <td class="px-4 py-3"><span class="rounded bg-gray-100 px-2 py-0.5 text-xs">{{ ucfirst($activity->category->value) }}</span></td>
                            <td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($activity->preferred_time_of_day)->format('g:i A') }}</td>
                            <td class="px-4 py-3">{{ $activity->frequency }}x/week</td>
                            <td class="px-4 py-3">{{ $activity->duration_minutes }} min</td>
                            <td class="px-4 py-3">{{ $streaks[$activity->id] }} {{ Str::plural('day', $streaks[$activity->id]) }}</td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1" aria-label="Recent history">
                                    @forelse ($activity->logs->reverse() as $log)
                                        <span class="h-2.5 w-2.5 rounded-full {{ $dotColor[$log->status] }}"
                                              title="{{ $log->scheduled_for->format('M j') }}: {{ ucfirst($log->status) }}"></span>
                                    @empty
                                        <span class="text-xs text-gray-400">No history yet</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1">
                                    @foreach (['completed' => 'Done', 'missed' => 'Missed', 'skipped' => 'Skip'] as $status => $label)
                                        <form method="POST" action="{{ route('activities.log', $activity) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="{{ $status }}">
                                            <button type="submit"
                                                    @class([
                                                        'rounded px-2 py-1 text-xs',
                                                        'bg-brand font-semibold' => $today?->status === $status,
                                                        'bg-gray-100 hover:bg-gray-200' => $today?->status !== $status,
                                                    ])>
                                                {{ $label }}
                                            </button>
                                        </form>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('recommendations.store', $activity) }}">
                                    @csrf
                                    <button type="submit" class="whitespace-nowrap rounded-full border border-gray-300 px-3 py-1 text-xs font-semibold hover:bg-gray-50">
                                        Get recommendation
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6 rounded-xl bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold text-gray-700">Activity Completion History (Past 7 Days)</h2>
            <canvas id="completion-chart" data-chart="{{ json_encode($completionByDay) }}" height="80"></canvas>
        </div>
    @endif
</x-layouts.app>
