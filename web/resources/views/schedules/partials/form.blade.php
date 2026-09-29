@php
    $date = old('date', $schedule->start_datetime?->format('Y-m-d') ?? request('date', now()->format('Y-m-d')));
    $start = old('start_time', $schedule->start_datetime?->format('H:i') ?? '09:00');
    $end = old('end_time', $schedule->end_datetime?->format('H:i') ?? '10:00');
    $category = old('category', $schedule->category?->value ?? 'work');
    $priority = old('priority', $schedule->priority ?? 'medium');
    $recurrence = old('recurrence', $schedule->recurrence_rule ?? 'none');
    $input = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none';
@endphp

<div class="space-y-4">
    <div>
        <label for="title" class="block text-xs text-gray-600">Task Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $schedule->title) }}" required autofocus
               placeholder="e.g. Team Sync Meeting" class="{{ $input }}">
        @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div>
            <label for="date" class="block text-xs text-gray-600">Date</label>
            <input id="date" name="date" type="date" value="{{ $date }}" required class="{{ $input }}">
            @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="start_time" class="block text-xs text-gray-600">Start Time</label>
            <input id="start_time" name="start_time" type="time" value="{{ $start }}" required class="{{ $input }}">
            @error('start_time') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="end_time" class="block text-xs text-gray-600">End Time</label>
            <input id="end_time" name="end_time" type="time" value="{{ $end }}" required class="{{ $input }}">
            @error('end_time') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="category" class="block text-xs text-gray-600">Category</label>
            <select id="category" name="category" class="{{ $input }}">
                @foreach (\App\Enums\Category::cases() as $option)
                    <option value="{{ $option->value }}" @selected($category === $option->value)>{{ ucfirst($option->value) }}</option>
                @endforeach
            </select>
            @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="priority" class="block text-xs text-gray-600">Priority</label>
            <select id="priority" name="priority" class="{{ $input }}">
                @foreach (['low', 'medium', 'high'] as $option)
                    <option value="{{ $option }}" @selected($priority === $option)>{{ ucfirst($option) }}</option>
                @endforeach
            </select>
            @error('priority') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <fieldset>
        <legend class="block text-xs text-gray-600">Recurrence</legend>
        <div class="mt-1 inline-flex rounded-md border border-gray-300 bg-white p-1 text-sm">
            @foreach (['none' => 'None', 'daily' => 'Daily', 'weekly' => 'Weekly'] as $value => $label)
                <label class="cursor-pointer">
                    <input type="radio" name="recurrence" value="{{ $value }}" class="peer sr-only" @checked($recurrence === $value)>
                    <span class="block rounded px-3 py-1 text-gray-600 peer-checked:bg-brand peer-checked:font-semibold peer-checked:text-gray-900">{{ $label }}</span>
                </label>
            @endforeach
        </div>
        @error('recurrence') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </fieldset>

    <div>
        <label for="description" class="block text-xs text-gray-600">Description</label>
        <textarea id="description" name="description" rows="3" placeholder="Add notes here..." class="{{ $input }}">{{ old('description', $schedule->description) }}</textarea>
        @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
