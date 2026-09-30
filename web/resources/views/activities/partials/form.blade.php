@php
    $input = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none';
@endphp

<div class="space-y-4">
    <div>
        <label for="name" class="block text-xs text-gray-600">Activity Name</label>
        <input id="name" name="name" type="text" value="{{ old('name', $activity->name) }}" required autofocus
               placeholder="e.g. Morning Run" class="{{ $input }}">
        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="category" class="block text-xs text-gray-600">Category</label>
            <select id="category" name="category" class="{{ $input }}">
                @foreach (\App\Enums\Category::cases() as $option)
                    <option value="{{ $option->value }}" @selected(old('category', $activity->category?->value) === $option->value)>
                        {{ ucfirst($option->value) }}
                    </option>
                @endforeach
            </select>
            @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="preferred_time_of_day" class="block text-xs text-gray-600">Preferred Time</label>
            <input id="preferred_time_of_day" name="preferred_time_of_day" type="time"
                   value="{{ old('preferred_time_of_day', $activity->preferred_time_of_day ? substr($activity->preferred_time_of_day, 0, 5) : '07:00') }}"
                   required class="{{ $input }}">
            @error('preferred_time_of_day') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="frequency" class="block text-xs text-gray-600">Frequency (times per week)</label>
            <input id="frequency" name="frequency" type="number" min="1" max="7"
                   value="{{ old('frequency', $activity->frequency) }}" required class="{{ $input }}">
            @error('frequency') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="duration_minutes" class="block text-xs text-gray-600">Duration (minutes)</label>
            <input id="duration_minutes" name="duration_minutes" type="number" min="5" max="600" step="5"
                   value="{{ old('duration_minutes', $activity->duration_minutes) }}" required class="{{ $input }}">
            @error('duration_minutes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</div>
