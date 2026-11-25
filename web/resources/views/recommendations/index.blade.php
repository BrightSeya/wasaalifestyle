<x-layouts.app title="Recommendations | WasaaLifestyle" heading="Better Times for Your Activities">
    <header class="mb-5 overflow-hidden rounded-3xl border border-amber-100 bg-gradient-to-br from-amber-50 via-white to-orange-50 p-5 sm:p-7">
        <div class="flex items-start gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-300 to-orange-300 text-2xl text-amber-950 shadow-sm">✦</span>
            <div><p class="text-xs font-bold uppercase tracking-[.16em] text-amber-800">Personalized planning</p><h2 class="mt-1 text-xl font-semibold tracking-tight text-gray-950">Find a time that fits your life</h2><p class="mt-1 max-w-2xl text-sm leading-6 text-gray-600">Wasaa checks your activities against your schedule and explains the evidence behind each suggestion.</p></div>
        </div>
    </header>
    @if (session('status'))<div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ session('error') }}</div>@endif

    <p class="mb-5 text-sm text-gray-600">Choose conflict-free sessions to add your activities to your schedule. Repeatable activities can have multiple suggestions based on their weekly frequency. We show up to four at once; use the page controls to see more.</p>

    @if ($error)
        <div role="status" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ $error }}</div>
    @endif

    @if ($recommendations->isEmpty())
        <div class="rounded-2xl border border-gray-200 bg-white p-7 text-center shadow-sm">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-2xl text-amber-800">✦</span>
            <p class="mt-3 text-sm leading-6 text-gray-600">
            @if (auth()->user()->activities()->exists())
                No suitable conflict-free time improvements were found for your current activities.
            @else
                Add an activity first. We’ll look for a better time for it here.
            @endif
            </p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($recommendations as $suggestion)
                <article class="group overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-amber-200 hover:shadow-lg">
                    <div class="flex items-start justify-between gap-3 bg-gradient-to-r from-amber-50/90 via-white to-orange-50/60 px-5 py-4">
                        <div><span class="rounded-full border border-amber-200 bg-white/80 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-900">{{ ucfirst($suggestion['activity']->category->value) }}</span><h2 class="mt-2 font-semibold text-gray-950">{{ $suggestion['activity']->name }}</h2><p class="mt-1 text-xs text-gray-500">{{ $suggestion['activity']->duration_minutes }} minutes · suggested time</p></div>
                        <span class="shrink-0 rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-2 text-center text-xs font-bold text-emerald-800"><span class="block text-lg leading-5">{{ round($suggestion['confidence'] * 100) }}%</span>fit</span>
                    </div>
                    <div class="p-5"><div class="flex items-center gap-3 rounded-xl border border-gray-100 bg-gray-50 p-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-amber-800 shadow-sm">◷</span><div><p class="text-xs font-medium text-gray-500">Suggested time</p><p class="text-sm font-semibold text-gray-900">{{ $suggestion['start']->format('l, M j') }} · {{ $suggestion['start']->format('g:i A') }}–{{ $suggestion['end']->format('g:i A') }}</p></div></div>
                    <div class="mt-3 rounded-xl border border-amber-100 bg-amber-50/80 px-3.5 py-3"><p class="text-[10px] font-bold uppercase tracking-wide text-amber-900">Why this suggestion</p><p class="mt-1 text-xs leading-5 text-amber-950">{{ $suggestion['evidence'] }}</p></div>
                    <button type="button" data-add-activity-slot
                            data-url="{{ route('recommendations.choose-activity-slot', $suggestion['activity']) }}"
                            data-start="{{ $suggestion['start']->toIso8601String() }}"
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand px-4 py-2.5 text-sm font-bold text-gray-950 shadow-sm hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2 disabled:opacity-60">
                        Add this time to my schedule
                    </button>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="mt-5">{{ $recommendations->links() }}</div>
    @endif

    <p class="mt-5 text-xs text-gray-500" data-page-status role="status" aria-live="polite"></p>
    <script>
        document.querySelectorAll('[data-add-activity-slot]').forEach(button => {
            button.addEventListener('click', async () => {
                const post = async confirmed => fetch(button.dataset.url, {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
                    body: JSON.stringify({start: button.dataset.start, confirmed})
                });
                const status = document.querySelector('[data-page-status]');
                button.disabled = true;
                try {
                    let response = await post(false);
                    let data = await response.json();
                    if (response.status === 409) {
                        if (!window.confirm(`${data.message}\n${data.conflicts.join('\n')}\n\nAdd it anyway?`)) { button.disabled = false; return; }
                        response = await post(true);
                        data = await response.json();
                    }
                    if (!response.ok) throw new Error(data.message || 'Could not add this activity.');
                    status.textContent = data.message;
                    button.textContent = 'Added to schedule';
                } catch (error) {
                    status.textContent = error.message;
                    button.disabled = false;
                }
            });
        });
    </script>
</x-layouts.app>
