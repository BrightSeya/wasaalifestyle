<x-layouts.app title="Activities | WasaaLifestyle" heading="Lifestyle Activities">
    @if (session('error'))
        <div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-4 flex justify-end">
        <a href="{{ route('activities.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" />Add activity</a>
    </div>

    @if ($activities->isEmpty())
        <p class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-500 shadow-sm">No activities yet. Add your first one.</p>
    @else
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
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
                                <form method="POST" action="{{ route('recommendations.store', $activity) }}" data-activity-recommend-form>
                                    @csrf
                                    <button type="submit" aria-haspopup="dialog" class="whitespace-nowrap rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-950 shadow-sm hover:border-amber-300 hover:bg-amber-100 disabled:cursor-wait disabled:opacity-60">
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
            <canvas id="completion-chart" data-completion-chart data-chart="{{ json_encode($completionByDay) }}" height="80"></canvas>
        </div>
    @endif

    <dialog data-activity-recommend-dialog class="wasaa-recommend-dialog rounded-3xl shadow-2xl" aria-labelledby="activity-recommend-title">
        <section class="overflow-hidden rounded-3xl bg-white text-gray-900">
            <header class="border-b border-amber-100 bg-gradient-to-br from-amber-50 via-white to-orange-50 px-6 py-5 sm:px-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.16em] text-amber-800">Wasaa time guide</p>
                    <h2 id="activity-recommend-title" class="mt-1 text-xl font-semibold tracking-tight text-gray-950" data-dialog-title>Best times for this activity</h2>
                    <p class="mt-1 text-sm text-gray-600">Choose a time that works for you. We’ll add it to your schedule.</p>
                </div>
                <button type="button" data-dialog-close class="rounded-xl border border-gray-200 bg-white/80 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-white">Close</button>
            </div>
            </header>
            <div class="px-6 py-5 sm:px-7">
                <p class="mb-3 text-sm text-gray-600" data-dialog-status aria-live="polite"></p>
                <div class="space-y-3" data-dialog-results></div>
            </div>
        </section>
    </dialog>

    <script>
        (() => {
            const dialog = document.querySelector('[data-activity-recommend-dialog]');
            const title = dialog?.querySelector('[data-dialog-title]');
            const status = dialog?.querySelector('[data-dialog-status]');
            const results = dialog?.querySelector('[data-dialog-results]');
            if (!dialog) return;
            dialog.querySelector('[data-dialog-close]').addEventListener('click', () => dialog.close());
            dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });

            const sendChoice = async (button, url, recommendationId, token, confirmed = false) => {
                button.disabled = true;
                try {
                    const response = await fetch(url, {
                        method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token},
                        body: JSON.stringify({recommendation_id: recommendationId, confirmed})
                    });
                    const data = await response.json();
                    if (response.status === 409 && !confirmed) {
                        status.textContent = `${data.message} ${data.conflicts.join(' ')}`;
                        status.className = 'rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900';
                        button.textContent = 'Use this time anyway';
                        button.className = 'rounded-xl bg-rose-100 px-4 py-2 text-xs font-semibold text-rose-900 hover:bg-rose-200';
                        button.disabled = false;
                        button.dataset.confirmed = '1';
                        return;
                    }
                    if (!response.ok) throw new Error(data.message || 'Could not add this time.');
                    status.textContent = data.message;
                    status.className = 'rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900';
                    results.replaceChildren();
                } catch (error) {
                    status.textContent = error.message;
                    status.className = 'rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900';
                    button.disabled = false;
                }
            };

            document.querySelectorAll('[data-activity-recommend-form]').forEach(form => {
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    dialog.showModal();
                    title.textContent = `Best times for ${form.closest('tr').querySelector('td a').textContent.trim()}`;
                    status.className = 'mb-3 text-sm text-gray-600';
                    status.textContent = 'Finding suitable times…';
                    results.replaceChildren();
                    const trigger = form.querySelector('button[type="submit"]');
                    trigger.disabled = true;
                    try {
                        const response = await fetch(form.action, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value}});
                        const data = await response.json();
                        if (!response.ok) throw new Error(data.message || 'Recommendations are temporarily unavailable.');
                        const suggestions = data.recommendations.slice(0, 4);
                        if (!suggestions.length) { status.textContent = 'No suitable time suggestions are available right now.'; return; }
                        status.textContent = 'Choose one of these times or close this window.';
                        suggestions.forEach((slot, index) => {
                            const start = new Date(slot.start);
                            const end = new Date(slot.end);
                            const card = document.createElement('article');
                            card.className = 'overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition hover:border-amber-300 hover:shadow-md';
                            const top = document.createElement('div');
                            top.className = 'flex flex-wrap items-center justify-between gap-3 px-4 pt-4';
                            const number = document.createElement('span');
                            number.className = 'inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-gray-500';
                            number.textContent = `Option ${index + 1}`;
                            const score = document.createElement('span');
                            score.className = 'rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800';
                            score.textContent = `${Math.round(slot.confidence * 100)}% match`;
                            top.append(number, score);
                            const details = document.createElement('div');
                            details.className = 'px-4 pb-4 pt-2';
                            const time = document.createElement('p');
                            time.className = 'text-base font-semibold text-gray-950';
                            time.textContent = `${start.toLocaleDateString(undefined, {weekday: 'long', month: 'short', day: 'numeric'})} · ${start.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}–${end.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}`;
                            const evidence = document.createElement('p');
                            evidence.className = 'mt-3 rounded-xl border border-amber-100 bg-amber-50/80 px-3 py-2.5 text-xs leading-5 text-amber-950';
                            const evidenceLabel = document.createElement('span');
                            evidenceLabel.className = 'mb-0.5 block font-bold text-amber-900';
                            evidenceLabel.textContent = 'Why this suggestion';
                            const evidenceText = document.createElement('span');
                            evidenceText.textContent = slot.evidence || 'This time is ranked using your activity category, duration, schedule, and learned time-use patterns.';
                            evidence.append(evidenceLabel, evidenceText);
                            const choice = document.createElement('button');
                            choice.type = 'button';
                            choice.className = 'mt-3 rounded-xl bg-brand px-4 py-2 text-xs font-bold text-gray-950 shadow-sm hover:bg-brand-dark disabled:opacity-60';
                            choice.textContent = 'Choose this time';
                            choice.addEventListener('click', () => sendChoice(choice, `${form.action}/choose`, slot.id, form.querySelector('[name="_token"]').value, choice.dataset.confirmed === '1'));
                            details.append(time, evidence, choice);
                            card.append(top, details);
                            results.append(card);
                        });
                    } catch (error) {
                        status.textContent = error.message;
                    } finally {
                        trigger.disabled = false;
                    }
                });
            });
        })();
    </script>
</x-layouts.app>
