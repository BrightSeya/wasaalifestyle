@php
    $input = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none';
@endphp

<div class="space-y-4" data-activity-form-fields>
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

    @php
        $initialWeeklySlots = old('weekly_slots', $activity->weekly_slots ?? []);
        $activityConflicts = session('activity_conflicts');
        $activityConflictSlots = $activityConflicts['by_slot'] ?? [];
    @endphp
    <section class="mt-6 border-t border-gray-100 pt-5"
             data-activity-plan
             data-preview-url="{{ route('activities.weekly-plan.preview') }}"
             data-activity-id="{{ $activity->exists ? $activity->id : '' }}"
             data-initial-plan='@json($initialWeeklySlots)'
             data-plan-conflicts='@json($activityConflictSlots)'>
        <h2 class="text-sm font-semibold text-gray-800">Weekly schedule</h2>
        <p class="mt-1 text-xs leading-5 text-gray-600">Get a plan for the next seven days. Wasaa suggests different days and up to three suitable times for each session. The saved plan repeats weekly for one year.</p>
        <button type="button" data-generate-activity-plan class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-950 hover:bg-amber-100">
            Generate weekly recommendations
        </button>
        <p class="mt-2 text-xs text-gray-600" data-activity-plan-status aria-live="polite"></p>
        @foreach ($errors->getMessages() as $field => $messages)
            @if (str_starts_with($field, 'weekly_slots'))
                <p class="mt-1 text-xs text-red-600">{{ $messages[0] }}</p>
            @endif
        @endforeach
        @if ($activityConflicts)
            <input type="hidden" name="activity_conflict_fingerprint" value="{{ $activityConflicts['fingerprint'] }}">
            <div class="mt-3 rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-950"
                 role="alert" data-activity-conflict-warning>
                <p class="font-semibold">Some sessions overlap with your schedule.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    @foreach ($activityConflictSlots as $slotIndex => $details)
                        @foreach ($details as $detail)
                            <li>Session {{ (int) $slotIndex + 1 }}: {{ $detail['message'] }}</li>
                        @endforeach
                    @endforeach
                </ul>
                <p class="mt-2 text-xs">Change a highlighted day or time and save to check again, or confirm below to keep both entries scheduled.</p>
            </div>
            <button type="submit" name="confirmed" value="1" data-confirm-overlap
                    class="mt-3 w-full rounded-md border border-rose-400 bg-rose-100 px-3 py-2 text-sm font-semibold text-rose-950 hover:bg-rose-200">
                Confirm these overlapping times
            </button>
        @endif
        <div class="mt-3 space-y-3" data-activity-plan-days></div>
    </section>

    <script nonce="{{ request()->attributes->get('cspNonce', '') }}">
        (() => {
            const section = document.querySelector('[data-activity-plan]');
            const form = section?.closest('form');
            const cards = section?.querySelector('[data-activity-plan-days]');
            const status = section?.querySelector('[data-activity-plan-status]');
            const generateButton = section?.querySelector('[data-generate-activity-plan]');
            const conflictWarning = section?.querySelector('[data-activity-conflict-warning]');
            const confirmButton = section?.querySelector('[data-confirm-overlap]');
            if (!section || !form || !cards || !status || !generateButton) return;

            const dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const field = name => form.querySelector(`[name="${name}"]`);
            const activityId = section.dataset.activityId;
            const planConflicts = JSON.parse(section.dataset.planConflicts || '{}');

            const clearConflictWarning = () => {
                if (!conflictWarning || conflictWarning.hidden) return;
                conflictWarning.hidden = true;
                if (confirmButton) confirmButton.hidden = true;
                cards.querySelectorAll('[data-plan-conflict]').forEach(item => item.remove());
                cards.querySelectorAll('[data-plan-card]').forEach(card => {
                    card.classList.remove('border-2', 'border-rose-400', 'bg-rose-50');
                    card.classList.add('border-gray-200', 'bg-gray-50');
                });
                status.textContent = 'Plan changed. Save to check the updated schedule.';
            };

            const requestOptions = async (card, excludeTimes = []) => {
                const payload = {
                    mode: 'day',
                    category: field('category').value,
                    duration_minutes: Number(field('duration_minutes').value),
                    frequency: Number(field('frequency').value),
                    weekday: Number(card.querySelector('[data-plan-weekday]').value),
                    exclude_times: excludeTimes,
                };
                if (activityId) payload.activity_id = Number(activityId);

                const response = await fetch(section.dataset.previewUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'Recommendations are temporarily unavailable.');
                return data.options || [];
            };

            const addOption = (card, option, selected = false) => {
                const options = card.querySelector('[data-plan-options]');
                if ([...options.querySelectorAll('[data-plan-option-time]')].some(item => item.dataset.planOptionTime === option.start_time)) return;
                const wrapper = document.createElement('label');
                wrapper.className = 'flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs hover:border-amber-300';
                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = `plan-choice-${card.dataset.planIndex}`;
                radio.checked = selected;
                radio.dataset.planOptionTime = option.start_time;
                radio.dataset.confidence = option.confidence ?? '';
                const text = document.createElement('span');
                text.className = 'flex-1 font-medium text-gray-800';
                text.textContent = `${option.start_time_label || option.start_time} - ${option.end_time_label || ''}`;
                const match = document.createElement('span');
                match.className = 'rounded-full bg-emerald-50 px-2 py-1 font-semibold text-emerald-800';
                match.textContent = `${Math.round((option.confidence || 0) * 100)}%`;
                wrapper.append(radio, text, match);
                radio.addEventListener('change', () => {
                    card.querySelector('[data-plan-time]').value = option.start_time;
                    card.querySelector('[data-plan-confidence]').value = option.confidence ?? '';
                });
                options.append(wrapper);
                if (selected) {
                    card.querySelector('[data-plan-time]').value = option.start_time;
                    card.querySelector('[data-plan-confidence]').value = option.confidence ?? '';
                }
            };

            const makeCard = (index, slot, options = [], showConflicts = true) => {
                const card = document.createElement('article');
                card.dataset.planIndex = String(index);
                card.dataset.planCard = '';
                card.className = 'rounded-xl border border-gray-200 bg-gray-50 p-3';
                const heading = document.createElement('h3');
                heading.className = 'text-xs font-bold text-gray-800';
                heading.textContent = `Session ${index + 1}`;

                const controls = document.createElement('div');
                controls.className = 'mt-2 grid gap-3 sm:grid-cols-2';
                const dayLabel = document.createElement('label');
                dayLabel.className = 'text-xs text-gray-600';
                dayLabel.textContent = 'Day';
                const day = document.createElement('select');
                day.name = `weekly_slots[${index}][weekday]`;
                day.dataset.planWeekday = '';
                day.className = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm';
                dayNames.forEach((name, dayIndex) => {
                    const option = document.createElement('option');
                    option.value = String(dayIndex + 1);
                    option.textContent = name;
                    option.selected = Number(slot.weekday) === dayIndex + 1;
                    day.append(option);
                });
                dayLabel.append(day);

                const timeLabel = document.createElement('label');
                timeLabel.className = 'text-xs text-gray-600';
                timeLabel.textContent = 'Chosen time';
                const time = document.createElement('input');
                time.type = 'time';
                time.name = `weekly_slots[${index}][start_time]`;
                time.required = true;
                time.value = slot.start_time || '';
                time.dataset.planTime = '';
                time.className = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm';
                const confidence = document.createElement('input');
                confidence.type = 'hidden';
                confidence.name = `weekly_slots[${index}][confidence]`;
                confidence.value = slot.confidence ?? '';
                confidence.dataset.planConfidence = '';
                timeLabel.append(time, confidence);
                controls.append(dayLabel, timeLabel);

                const optionsList = document.createElement('div');
                optionsList.className = 'mt-2 space-y-2';
                optionsList.dataset.planOptions = '';
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'mt-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100';
                more.textContent = 'More times for this day';
                more.addEventListener('click', async () => {
                    more.disabled = true;
                    status.textContent = `Finding more times for ${day.options[day.selectedIndex].text}...`;
                    try {
                        const excludeTimes = [...optionsList.querySelectorAll('[data-plan-option-time]')].map(option => option.dataset.planOptionTime);
                        if (time.value) excludeTimes.push(time.value);
                        const alternatives = await requestOptions(card, [...new Set(excludeTimes)]);
                        alternatives.forEach(option => addOption(card, option));
                        status.textContent = alternatives.length ? 'More suitable times are shown for that session.' : 'No additional suitable times were found for that day.';
                    } catch (error) {
                        status.textContent = error.message;
                    } finally {
                        more.disabled = false;
                    }
                });
                day.addEventListener('change', () => {
                    optionsList.replaceChildren();
                    time.value = '';
                    confidence.value = '';
                    more.disabled = true;
                    status.textContent = `Finding suitable times for ${day.options[day.selectedIndex].text}...`;
                    requestOptions(card).then(alternatives => {
                        alternatives.forEach((option, optionIndex) => addOption(card, option, optionIndex === 0));
                        status.textContent = alternatives.length ? 'Choose a recommended time for this day.' : 'No suitable times were found for that day.';
                    }).catch(error => {
                        status.textContent = error.message;
                    }).finally(() => {
                        more.disabled = false;
                    });
                });
                time.addEventListener('input', () => {
                    confidence.value = '';
                    optionsList.querySelectorAll('input[type="radio"]').forEach(option => { option.checked = false; });
                });

                card.append(heading, controls, optionsList, more);
                options.forEach((option, optionIndex) => addOption(card, option, optionIndex === 0));
                if (!options.length && time.value) {
                    const current = document.createElement('p');
                    current.className = 'mt-2 text-xs text-gray-500';
                    current.textContent = "Saved time. Use 'More times' to find alternatives.";
                    optionsList.append(current);
                }
                const conflictDetails = showConflicts ? (planConflicts[String(index)] || []) : [];
                if (conflictDetails.length) {
                    card.classList.remove('border-gray-200', 'bg-gray-50');
                    card.classList.add('border-2', 'border-rose-400', 'bg-rose-50');
                    conflictDetails.forEach(detail => {
                        const warning = document.createElement('p');
                        warning.dataset.planConflict = '';
                        warning.className = 'mt-2 text-xs font-semibold text-rose-800';
                        warning.textContent = detail.message;
                        card.append(warning);
                    });
                }
                return card;
            };

            const renderDays = (days, showConflicts = true) => {
                cards.replaceChildren();
                days.forEach((day, index) => {
                    const firstOption = day.options?.[0] || {};
                    cards.append(makeCard(index, {
                        weekday: day.weekday,
                        start_time: day.start_time || firstOption.start_time || '',
                        confidence: day.confidence ?? firstOption.confidence ?? '',
                    }, day.options || [], showConflicts));
                });
            };

            const initialPlan = JSON.parse(section.dataset.initialPlan || '[]');
            if (Array.isArray(initialPlan) && initialPlan.length) {
                renderDays(initialPlan.map(slot => ({
                    weekday: Number(slot.weekday),
                    start_time: slot.start_time,
                    confidence: slot.confidence,
                    options: slot.confidence == null ? [] : [{
                        start_time: slot.start_time,
                        start_time_label: new Date(`1970-01-01T${slot.start_time}:00Z`).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit', timeZone: 'UTC'}),
                        confidence: Number(slot.confidence),
                    }],
                })));
            }

            generateButton.addEventListener('click', async () => {
                if (!field('category').value || !field('duration_minutes').value || !field('frequency').value) {
                    status.textContent = 'Choose a category, duration, and weekly frequency first.';
                    return;
                }
                clearConflictWarning();
                generateButton.disabled = true;
                status.textContent = 'Finding a balanced weekly plan...';
                try {
                    const payload = {
                        mode: 'plan',
                        category: field('category').value,
                        duration_minutes: Number(field('duration_minutes').value),
                        frequency: Number(field('frequency').value),
                    };
                    if (activityId) payload.activity_id = Number(activityId);
                    const response = await fetch(section.dataset.previewUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                        },
                        body: JSON.stringify(payload),
                    });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Recommendations are temporarily unavailable.');
                    renderDays(data.days || [], false);
                    status.textContent = data.message || 'Choose a time for each weekly session.';
                } catch (error) {
                    status.textContent = error.message;
                } finally {
                    generateButton.disabled = false;
                }
            });

            form.addEventListener('input', event => {
                if (event.target.name?.startsWith('weekly_slots') || ['name', 'category', 'frequency', 'duration_minutes'].includes(event.target.name)) {
                    clearConflictWarning();
                }
            });
            form.addEventListener('change', event => {
                if (event.target.name?.startsWith('weekly_slots') || ['name', 'category', 'frequency', 'duration_minutes'].includes(event.target.name)) {
                    clearConflictWarning();
                }
            });

            form.addEventListener('submit', event => {
                const slotCards = [...cards.children];
                const weekdays = slotCards.map(card => Number(card.querySelector('[data-plan-weekday]').value));
                if (slotCards.length !== Number(field('frequency').value) || new Set(weekdays).size !== weekdays.length) {
                    event.preventDefault();
                    status.textContent = 'Generate or adjust the weekly plan so it has one different day for each session.';
                }
            });
        })();
    </script>

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
