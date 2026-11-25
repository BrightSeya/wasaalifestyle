<x-layouts.app title="Recommendations | WasaaLifestyle" heading="Recommended Schedule Changes">
    <header class="mb-5 overflow-hidden rounded-3xl border border-amber-100 bg-gradient-to-br from-amber-50 via-white to-orange-50 p-5 sm:p-7">
        <div class="flex items-start gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-300 to-orange-300 text-2xl text-amber-950 shadow-sm">✦</span>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.16em] text-amber-800">Personalized planning</p>
                <h2 class="mt-1 text-xl font-semibold tracking-tight text-gray-950">Find a better time for a scheduled item</h2>
                <p class="mt-1 max-w-2xl text-sm leading-6 text-gray-600">Each suggestion names the task or lifestyle activity, its current time, and the proposed time. Applying a change to a repeating item moves only that occurrence.</p>
            </div>
        </div>
    </header>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ session('error') }}</div>
    @endif

    <p class="mb-5 text-sm text-gray-600">Review upcoming recommendations and apply or dismiss them. Four suggestions are shown per page.</p>

    @if ($error)
        <div role="status" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ $error }}</div>
    @endif

    @if ($recommendations->isEmpty())
        <div class="rounded-2xl border border-gray-200 bg-white p-7 text-center shadow-sm">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-2xl text-amber-800">✦</span>
            <p class="mt-3 text-sm leading-6 text-gray-600">There are no upcoming scheduled tasks or lifestyle activities with a suitable alternative time right now.</p>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($recommendations as $suggestion)
                @php
                    $recommendation = $suggestion['recommendation'];
                    $schedule = $suggestion['schedule'];
                    $conflicts = session('conflicts', [])[$recommendation->id] ?? [];
                @endphp
                <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-start justify-between gap-3 bg-gradient-to-r from-amber-50/90 via-white to-orange-50/60 px-5 py-4">
                        <div>
                            <span class="rounded-full border border-amber-200 bg-white/80 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-amber-900">
                                {{ $schedule->activity_id ? 'Lifestyle activity' : 'Task' }}
                            </span>
                            <h2 class="mt-2 font-semibold text-gray-950">{{ $schedule->title }}</h2>
                            <p class="mt-1 text-xs text-gray-500">{{ $schedule->category->value }} · {{ $suggestion['old_start']->diffInMinutes($suggestion['old_end']) }} minutes</p>
                        </div>
                        <span class="shrink-0 rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-2 text-center text-xs font-bold text-emerald-800">
                            <span class="block text-lg leading-5">{{ round($suggestion['confidence'] * 100) }}%</span>fit
                        </span>
                    </div>

                    <div class="space-y-3 p-5">
                        @if ($schedule->is_recurring)
                            <p class="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-medium text-blue-900">
                                Repeating schedule: only the {{ $suggestion['old_start']->format('l, M j') }} occurrence will change. Other occurrences stay as scheduled.
                            </p>
                        @endif

                        <div class="grid gap-2 sm:grid-cols-2">
                            <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                                <p class="text-xs font-medium text-gray-500">Current time</p>
                                <p class="mt-1 text-sm font-semibold text-gray-900">{{ $suggestion['old_start']->format('D, M j · g:i A') }}–{{ $suggestion['old_end']->format('g:i A') }}</p>
                            </div>
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
                                <p class="text-xs font-medium text-amber-800">Recommended time</p>
                                <p class="mt-1 text-sm font-semibold text-amber-950">{{ $suggestion['new_start']->format('D, M j · g:i A') }}–{{ $suggestion['new_end']->format('g:i A') }}</p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-amber-100 bg-amber-50/80 px-3.5 py-3">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-amber-900">Why this suggestion</p>
                            <p class="mt-1 text-xs leading-5 text-amber-950">{{ $suggestion['evidence'] }}</p>
                        </div>

                        @if ($conflicts)
                            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900" role="alert">
                                <p class="font-semibold">The schedule has changed and this time now conflicts:</p>
                                <ul class="mt-1 list-disc pl-5">
                                    @foreach ($conflicts as $conflict)
                                        <li>{{ $conflict }}</li>
                                    @endforeach
                                </ul>
                                <form method="POST" action="{{ route('recommendations.accept', $recommendation) }}" class="mt-3">
                                    @csrf
                                    <input type="hidden" name="confirmed" value="1">
                                    <button type="submit" class="rounded-lg bg-rose-700 px-3 py-2 font-semibold text-white hover:bg-rose-800">Apply anyway</button>
                                </form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('recommendations.accept', $recommendation) }}">
                                @csrf
                                <button type="submit" class="w-full rounded-xl bg-brand px-4 py-2.5 text-sm font-bold text-gray-950 shadow-sm hover:bg-brand-dark">
                                    Apply recommended change
                                </button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('recommendations.dismiss', $recommendation) }}">
                            @csrf
                            <button type="submit" class="w-full rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">Dismiss</button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>
        <div class="mt-5">{{ $recommendations->links() }}</div>
    @endif
</x-layouts.app>
