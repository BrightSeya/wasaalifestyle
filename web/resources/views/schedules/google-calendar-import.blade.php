<x-layouts.app title="Import Google Calendar | WasaaLifestyle" heading="Import Google Calendar Events">
    <div class="max-w-3xl space-y-6">
        @error('calendar_import')
            <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
        @enderror

        <section class="rounded-xl bg-white p-6 shadow-sm">
            @if (! $connected)
                <h2 class="text-base font-semibold">Connect your calendar first</h2>
                <p class="mt-2 text-sm text-gray-600">Connect a Google Calendar account in Settings before importing events.</p>
                <a href="{{ route('settings.edit') }}" class="mt-4 inline-flex rounded-md bg-brand px-4 py-2 text-sm font-semibold">Open Settings</a>
            @else
                <h2 class="text-base font-semibold">Choose an event date range</h2>
                <p class="mt-1 text-sm text-gray-600">Select up to 31 days. Events are reviewed for duplicates and schedule conflicts before anything is saved.</p>

                <form method="POST" action="{{ route('schedules.google-calendar.preview') }}" class="mt-5 space-y-4">
                    @csrf
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="date_from" class="block text-xs text-gray-600">From</label>
                            <input id="date_from" type="date" name="date_from" value="{{ old('date_from', $dateFrom) }}" required class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            @error('date_from') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="date_to" class="block text-xs text-gray-600">Through</label>
                            <input id="date_to" type="date" name="date_to" value="{{ old('date_to', $dateTo) }}" required class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            @error('date_to') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="category" class="block text-xs text-gray-600">Category for imported events</label>
                            <select id="category" name="category" required class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm">
                                @foreach ($categories as $category)
                                    <option value="{{ $category->value }}" @selected(old('category', 'work') === $category->value)>{{ ucfirst($category->value) }}</option>
                                @endforeach
                            </select>
                            @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">Imported entries use Medium priority and the selected category. Dates and times are stored in the app time zone; each event’s original Google time zone is retained.</p>
                    <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Review events</button>
                </form>
            @endif
        </section>
    </div>
</x-layouts.app>
