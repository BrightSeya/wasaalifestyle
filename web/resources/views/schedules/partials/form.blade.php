@php
    $timezone = auth()->user()->timezone ?? 'Africa/Nairobi';
    $date = old('date', $schedule->start_datetime?->copy()->setTimezone($timezone)->format('Y-m-d') ?? request('date', now($timezone)->format('Y-m-d')));
    $endDate = old('end_date', $schedule->is_all_day ? $schedule->end_datetime?->copy()->setTimezone($timezone)->subDay()->format('Y-m-d') : $date);
    $isAllDay = (bool) old('is_all_day', $schedule->is_all_day ?? false);
    $start = old('start_time', $schedule->start_datetime?->copy()->setTimezone($timezone)->format('H:i') ?? '09:00');
    $end = old('end_time', $schedule->end_datetime?->copy()->setTimezone($timezone)->format('H:i') ?? '10:00');
    $category = old('category', $schedule->category?->value ?? 'work');
    $priority = old('priority', $schedule->priority ?? 'medium');
    $recurrence = old('recurrence', $schedule->recurrence_rule ?? 'none');
    $recurrenceWeekday = (int) old('recurrence_weekday', $schedule->start_datetime?->copy()->setTimezone($timezone)->dayOfWeekIso ?? now($timezone)->dayOfWeekIso);
    $recurrenceEnds = old('recurrence_ends', $schedule->recurrence_end_date ? 'on_date' : ($schedule->recurrence_count ? 'after' : ''));
    $recurrenceUntil = old('recurrence_until', $schedule->recurrence_end_date?->format('Y-m-d'));
    $recurrenceCount = old('recurrence_count', $schedule->recurrence_count ?? 10);
    $input = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none';
@endphp

<div class="space-y-4">
    @if (session('conflicts'))
        <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
            <p class="font-semibold">This clashes with your existing schedule:</p>
            <ul class="mt-1 list-disc pl-4">
                @foreach (session('conflicts') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
            @if ($recurrence !== 'none')
                <p class="mt-2">If you submit again, only these dates will be skipped. The rest of the repeating task will stay scheduled.</p>
            @else
                <p class="mt-2">Submitting again will save it anyway.</p>
            @endif
        </div>
        <input type="hidden" name="confirmed" value="1">
    @endif

    <div>
        <label for="title" class="block text-xs text-gray-600">Task Title</label>
        <input id="title" name="title" type="text" value="{{ old('title', $schedule->title) }}" required autofocus
               placeholder="e.g. Team Sync Meeting" class="{{ $input }}">
        @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
        <input id="is_all_day" name="is_all_day" type="checkbox" value="1" @checked($isAllDay) class="rounded border-gray-300 text-brand focus:ring-brand">
        All-day entry
    </label>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="date" data-recurrence-date-label class="block text-xs text-gray-600">Date</label>
            <input id="date" name="date" type="date" value="{{ $date }}" required class="{{ $input }}">
            @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div data-all-day-end-field hidden>
            <label for="end_date" class="block text-xs text-gray-600">Last day</label>
            <input id="end_date" name="end_date" type="date" value="{{ $endDate }}" class="{{ $input }}">
            @error('end_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div data-weekly-day-picker hidden>
            <label for="recurrence_weekday" class="block text-xs text-gray-600">Repeats every</label>
            <select id="recurrence_weekday" name="recurrence_weekday" class="{{ $input }}" disabled>
                @foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $weekday => $weekdayLabel)
                    <option value="{{ $weekday }}" @selected($recurrenceWeekday === $weekday)>{{ $weekdayLabel }}</option>
                @endforeach
            </select>
            @error('recurrence_weekday') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>

    <div data-time-fields class="grid gap-4 sm:grid-cols-2">
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
            <select id="category" name="category" required class="{{ $input }}">
                @foreach (\App\Enums\Category::cases() as $option)
                    <option value="{{ $option->value }}" @selected($category === $option->value)>{{ ucfirst($option->value) }}</option>
                @endforeach
            </select>
            @error('category') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="priority" class="block text-xs text-gray-600">Priority</label>
            <select id="priority" name="priority" required class="{{ $input }}">
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
                    <input type="radio" name="recurrence" value="{{ $value }}" class="peer sr-only" @if ($loop->first) required @endif @checked($recurrence === $value)>
                    <span class="block rounded px-3 py-1 text-gray-600 peer-checked:bg-brand peer-checked:font-semibold peer-checked:text-gray-900">{{ $label }}</span>
                </label>
            @endforeach
        </div>
        @error('recurrence') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </fieldset>
    <p data-recurrence-summary class="text-xs leading-5 text-gray-600" aria-live="polite"></p>
    <div data-recurrence-end-options class="grid gap-3 sm:grid-cols-2">
        <div>
            <label for="recurrence_ends" class="block text-xs text-gray-600">Ends</label>
            <select id="recurrence_ends" name="recurrence_ends" class="{{ $input }}">
                <option value="" disabled @selected($recurrenceEnds === '')>Choose an end</option>
                <option value="on_date" @selected($recurrenceEnds === 'on_date')>On a date</option>
                <option value="after" @selected($recurrenceEnds === 'after')>After a number of occurrences</option>
            </select>
            @error('recurrence_ends') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div data-recurrence-until-field hidden>
            <label for="recurrence_until" class="block text-xs text-gray-600">Last date</label>
            <input id="recurrence_until" name="recurrence_until" type="date" value="{{ $recurrenceUntil }}" class="{{ $input }}" disabled>
            @error('recurrence_until') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div data-recurrence-count-field hidden>
            <label for="recurrence_count" class="block text-xs text-gray-600">Number of occurrences (including the first)</label>
            <input id="recurrence_count" name="recurrence_count" type="number" min="1" max="367" value="{{ $recurrenceCount }}" class="{{ $input }}" disabled>
            @error('recurrence_count') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <p class="text-xs text-gray-500 sm:col-span-2">Repeating tasks are limited to one year from their first occurrence.</p>
    </div>

    <div>
        <label for="description" class="block text-xs text-gray-600">Description</label>
        <textarea id="description" name="description" rows="3" placeholder="Add notes here..." class="{{ $input }}">{{ old('description', $schedule->description) }}</textarea>
        @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>
</div>

<script nonce="{{ request()->attributes->get('cspNonce', '') }}">
    (() => {
        const date = document.querySelector('#date');
        const dateLabel = document.querySelector('[data-recurrence-date-label]');
        const weekdayPicker = document.querySelector('[data-weekly-day-picker]');
        const weekday = document.querySelector('#recurrence_weekday');
        const recurrenceEnds = document.querySelector('#recurrence_ends');
        const recurrenceUntilField = document.querySelector('[data-recurrence-until-field]');
        const recurrenceCountField = document.querySelector('[data-recurrence-count-field]');
        const recurrenceUntil = document.querySelector('#recurrence_until');
        const recurrenceCount = document.querySelector('#recurrence_count');
        const startTime = document.querySelector('#start_time');
        const endTime = document.querySelector('#end_time');
        const allDay = document.querySelector('#is_all_day');
        const endDate = document.querySelector('#end_date');
        const allDayEndField = document.querySelector('[data-all-day-end-field]');
        const timeFields = document.querySelector('[data-time-fields]');
        const summary = document.querySelector('[data-recurrence-summary]');
        const recurrenceInputs = [...document.querySelectorAll('[name="recurrence"]')];
        if (!date || !dateLabel || !weekdayPicker || !weekday || !recurrenceEnds || !recurrenceUntilField || !recurrenceCountField || !recurrenceUntil || !recurrenceCount || !startTime || !endTime || !allDay || !endDate || !allDayEndField || !timeFields || !summary || !recurrenceInputs.length) return;

        const selectedRecurrence = () => recurrenceInputs.find(input => input.checked)?.value || 'none';
        const displayTime = value => {
            if (!value) return '';
            const [hour, minute] = value.split(':').map(Number);
            return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
        };
        const dateOnly = value => new Date(`${value}T00:00:00Z`);
        const formatDateOnly = value => dateOnly(value).toLocaleDateString(undefined, {
            weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC',
        });
        const updateRecurrenceFields = () => {
            const isAllDay = allDay.checked;
            allDayEndField.hidden = !isAllDay;
            endDate.disabled = !isAllDay;
            endDate.required = isAllDay;
            timeFields.hidden = isAllDay;
            startTime.disabled = isAllDay;
            endTime.disabled = isAllDay;
            recurrenceInputs.forEach(input => {
                input.disabled = isAllDay && input.value !== 'none';
            });
            if (isAllDay) {
                recurrenceInputs.find(input => input.value === 'none').checked = true;
            }
            const recurrence = selectedRecurrence();
            dateLabel.textContent = recurrence === 'none' ? 'Date' : 'Starts on';
            weekdayPicker.hidden = recurrence !== 'weekly';
            weekday.disabled = recurrence !== 'weekly';
            weekday.required = recurrence === 'weekly';
            const recurring = recurrence !== 'none';
            recurrenceEnds.disabled = !recurring;
            recurrenceEnds.required = recurring;
            recurrenceEnds.parentElement.hidden = !recurring;
            recurrenceUntilField.hidden = !recurring || recurrenceEnds.value !== 'on_date';
            recurrenceUntil.disabled = recurrenceUntilField.hidden;
            recurrenceUntil.required = !recurrenceUntilField.hidden;
            recurrenceCountField.hidden = !recurring || recurrenceEnds.value !== 'after';
            recurrenceCount.disabled = recurrenceCountField.hidden;
            recurrenceCount.required = !recurrenceCountField.hidden;

            if (isAllDay || !date.value) {
                summary.textContent = '';
                return;
            }
            if (recurring && !recurrenceEnds.value) {
                summary.textContent = 'Choose an end date or occurrence limit for this repeating task.';
                return;
            }

            const firstDate = dateOnly(date.value);
            if (recurrence === 'weekly') {
                const targetDay = Number(weekday.value);
                const daysUntil = (targetDay - (firstDate.getUTCDay() || 7) + 7) % 7;
                firstDate.setUTCDate(firstDate.getUTCDate() + daysUntil);
            }
            const maximumEndDate = new Date(firstDate);
            maximumEndDate.setUTCFullYear(maximumEndDate.getUTCFullYear() + 1);
            recurrenceUntil.max = maximumEndDate.toISOString().slice(0, 10);
            const daysInLimit = Math.round((maximumEndDate - firstDate) / 86400000);
            recurrenceCount.max = String(Math.floor(daysInLimit / (recurrence === 'weekly' ? 7 : 1)) + 1);

            const timeRange = `${displayTime(startTime.value)} to ${displayTime(endTime.value)}`;
            const ending = recurrenceEnds.value === 'on_date'
                ? recurrenceUntil.value
                    ? ` until ${dateOnly(recurrenceUntil.value).toLocaleDateString(undefined, {month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC'})}`
                    : ' until you choose a date'
                : recurrenceCount.value ? ` for ${recurrenceCount.value} occurrences` : ' for the number of occurrences you choose';
            if (recurrence === 'daily') {
                summary.textContent = `Every day, ${timeRange}, starting ${formatDateOnly(date.value)}${ending}.`;
                return;
            }
            if (recurrence === 'weekly') {
                const dayName = weekday.options[weekday.selectedIndex].text;
                summary.textContent = `Every ${dayName}, ${timeRange}. First occurrence: ${firstDate.toLocaleDateString(undefined, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC'})}${ending}.`;
                return;
            }

            summary.textContent = '';
        };

        recurrenceInputs.forEach(input => input.addEventListener('change', updateRecurrenceFields));
        allDay.addEventListener('change', updateRecurrenceFields);
        document.addEventListener('schedule:fields-updated', updateRecurrenceFields);
        date.addEventListener('change', updateRecurrenceFields);
        weekday.addEventListener('change', updateRecurrenceFields);
        recurrenceEnds.addEventListener('change', updateRecurrenceFields);
        recurrenceUntil.addEventListener('change', updateRecurrenceFields);
        recurrenceCount.addEventListener('input', updateRecurrenceFields);
        startTime.addEventListener('change', updateRecurrenceFields);
        endTime.addEventListener('change', updateRecurrenceFields);
        updateRecurrenceFields();
    })();
</script>
