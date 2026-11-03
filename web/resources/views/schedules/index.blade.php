<x-layouts.app title="Schedule | WasaaLifestyle" heading="Schedule">
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-600">Upcoming tasks and events</p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('schedules.google-calendar.import') }}" class="btn-secondary">Import calendar</a>
            <a href="{{ route('schedules.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" />New task</a>
        </div>
    </div>

    @if ($schedules->isEmpty())
        <p class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-500 shadow-sm">Nothing scheduled yet. Create your first task.</p>
    @else
        <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white shadow-sm">
            @foreach ($schedules as $schedule)
                <li>
                    <a href="{{ route('schedules.edit', $schedule) }}" class="flex items-center justify-between gap-4 px-4 py-4 transition-colors hover:bg-gray-50">
                        <div>
                            <p class="font-medium">{{ $schedule->title }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $schedule->start_datetime->format('D, M j') }} &middot;
                                {{ $schedule->start_datetime->format('g:i A') }} - {{ $schedule->end_datetime->format('g:i A') }}
                                @if ($schedule->timezone) &middot; Google Calendar source zone {{ $schedule->timezone }} @endif
                                @if ($schedule->is_recurring) &middot; {{ ucfirst($schedule->recurrence_rule) }} @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2 text-xs">
                            <span class="rounded bg-gray-100 px-2 py-0.5">{{ ucfirst($schedule->category->value) }}</span>
                            <span class="rounded bg-brand/30 px-2 py-0.5">{{ ucfirst($schedule->priority) }}</span>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $schedules->links() }}</div>
    @endif
</x-layouts.app>
