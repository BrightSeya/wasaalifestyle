<x-layouts.app title="New Task | WasaaLifestyle" heading="Create New Task">
    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
        <form method="POST" action="{{ route('schedules.store') }}" class="rounded-xl bg-white p-6 shadow-sm" data-task-form>
            @csrf
            @include('schedules.partials.form')

            <div class="mt-6 flex gap-3">
                <a href="{{ route('schedules.index') }}" class="flex-1 rounded-md border border-gray-300 py-2 text-center text-sm">Cancel</a>
                <button type="submit" class="flex-1 rounded-md bg-brand py-2 text-sm font-semibold hover:bg-brand-dark">Save Task</button>
            </div>
        </form>

        <aside class="overflow-hidden rounded-2xl border border-amber-100 bg-white shadow-sm" aria-labelledby="task-recommendations-title">
            <div class="border-b border-amber-100 bg-gradient-to-br from-amber-50 via-white to-orange-50 p-5">
                <p class="text-xs font-bold uppercase tracking-[.16em] text-amber-800">Wasaa time guide</p>
                <h2 id="task-recommendations-title" class="mt-1 font-semibold text-gray-950">Find a better time</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">Choose the date in Task details, then get times that fit that specific day, your availability, and the task duration.</p>
            </div>
            <div class="p-5">
            <button type="button" data-task-recommend-button disabled class="mt-1 w-full rounded-xl bg-gray-100 px-4 py-2.5 text-sm font-bold text-gray-400 shadow-sm transition disabled:cursor-not-allowed enabled:bg-brand enabled:text-gray-950 enabled:hover:bg-brand-dark enabled:focus-visible:outline-none enabled:focus-visible:ring-2 enabled:focus-visible:ring-amber-600 enabled:focus-visible:ring-offset-2">
                Get recommendation
            </button>
            <p class="mt-3 text-sm text-gray-600" data-task-recommend-status aria-live="polite"></p>
            <div class="mt-3 space-y-2" data-task-recommendations></div>
            <details class="mt-4 border-t border-gray-100 pt-4" data-specific-task-date>
                <summary class="cursor-pointer text-sm font-semibold text-gray-800">Looking for a time on a specific date?</summary>
                <p class="mt-2 text-xs leading-5 text-gray-600">Choose a date and Wasaa will find available times for that day.</p>
                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <div>
                        <label for="recommendation-date" class="mb-1 block text-xs font-medium text-gray-700">Date</label>
                        <input id="recommendation-date" type="date" min="{{ today()->toDateString() }}" required data-recommendation-date class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    </div>
                    <button type="button" data-open-recommendation-calendar class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100">Open calendar</button>
                    <button type="button" data-find-date-times class="rounded-lg bg-brand px-3 py-2 text-xs font-bold text-gray-950 hover:bg-brand-dark">Find times for this date</button>
                </div>
            </details>
            <p class="mt-4 border-t border-gray-100 pt-3 text-[11px] leading-5 text-gray-500">Suggestions use your saved availability and, if enabled, your recent outcomes. New routines start from ATUS patterns.</p>
            </div>
        </aside>
    </div>

    <script>
        (() => {
            const form = document.querySelector('[data-task-form]');
            const button = document.querySelector('[data-task-recommend-button]');
            const dateInput = form?.querySelector('[name="date"]');
            const recommendationDateInput = document.querySelector('[data-recommendation-date]');
            const status = document.querySelector('[data-task-recommend-status]');
            const results = document.querySelector('[data-task-recommendations]');
            if (!form || !button) return;
            recommendationDateInput.value = dateInput.value;
            document.querySelector('[data-open-recommendation-calendar]').addEventListener('click', () => {
                if (typeof recommendationDateInput.showPicker === 'function') recommendationDateInput.showPicker();
                else recommendationDateInput.focus();
            });
            const sync = () => {
                button.disabled = !form.checkValidity();
                if (recommendationDateInput.value !== dateInput.value) recommendationDateInput.value = dateInput.value;
            };
            form.addEventListener('input', sync);
            form.addEventListener('change', sync);
            recommendationDateInput.addEventListener('change', () => {
                dateInput.value = recommendationDateInput.value;
                sync();
            });
            document.querySelector('[data-find-date-times]').addEventListener('click', () => {
                if (!recommendationDateInput.reportValidity()) return;
                dateInput.value = recommendationDateInput.value;
                sync();
                if (form.reportValidity()) button.click();
            });
            sync();

            const timeValue = value => {
                const date = new Date(value);
                return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
            };
            button.addEventListener('click', async () => {
                if (!form.reportValidity()) return;
                button.disabled = true;
                status.textContent = 'Finding suitable times…';
                results.replaceChildren();
                const payload = Object.fromEntries(new FormData(form).entries());
                try {
                    const response = await fetch(@json(route('recommendations.preview-task')), {
                        method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value},
                        body: JSON.stringify(payload)
                    });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Recommendations are temporarily unavailable.');
                    const suggestions = data.recommendations.slice(0, 4);
                    if (!suggestions.length) { status.textContent = 'No suitable times fit this date, availability, duration, and schedule. Choose another date or adjust the task details.'; return; }
                    status.textContent = 'Choose a time to update the task form. You can review it before saving.';
                    suggestions.forEach((slot, index) => {
                        const start = new Date(slot.start);
                        const end = new Date(slot.end);
                        const choice = document.createElement('button');
                        choice.type = 'button';
                        choice.className = 'w-full rounded-2xl border border-gray-200 bg-white p-3 text-left shadow-sm transition hover:border-amber-300 hover:bg-amber-50/60 hover:shadow-md';
                        const label = document.createElement('span');
                        label.className = 'flex items-center justify-between gap-2 text-[10px] font-bold uppercase tracking-wide text-amber-900';
                        label.textContent = `Option ${index + 1} · ${Math.round(slot.confidence * 100)}% match`;
                        const time = document.createElement('span');
                        time.className = 'mt-1 block text-sm font-semibold normal-case tracking-normal text-gray-900';
                        time.textContent = `${start.toLocaleDateString(undefined, {weekday: 'short', month: 'short', day: 'numeric'})}, ${start.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})} – ${end.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}`;
                        const evidence = document.createElement('span');
                        evidence.className = 'mt-2 block rounded-xl border border-amber-100 bg-amber-50 px-2.5 py-2 text-[11px] leading-5 text-amber-950';
                        evidence.textContent = `Why this time: ${slot.evidence || 'It fits the selected task category, duration, and learned time-use patterns.'}`;
                        choice.append(label, time, evidence);
                        choice.addEventListener('click', () => {
                            form.querySelector('[name="date"]').value = `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-${String(start.getDate()).padStart(2, '0')}`;
                            form.querySelector('[name="start_time"]').value = timeValue(slot.start);
                            form.querySelector('[name="end_time"]').value = timeValue(slot.end);
                            sync();
                            status.textContent = 'Suggested time added to the form. Review and save when ready.';
                        });
                        results.append(choice);
                    });
                } catch (error) {
                    status.textContent = error.message;
                } finally {
                    sync();
                }
            });
        })();
    </script>
</x-layouts.app>
