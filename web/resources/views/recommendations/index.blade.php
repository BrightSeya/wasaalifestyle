<x-layouts.app title="Recommendations | WasaaLifestyle" heading="Smart Scheduling Recommendations">
    @if ($recommendations->isEmpty())
        <p class="rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm">
            No pending recommendations. Open Activities and click "Get recommendation" on an activity.
        </p>
    @else
        <div class="space-y-4">
            @foreach ($recommendations as $recommendation)
                @php $conflicts = (session('conflicts') ?? [])[$recommendation->id] ?? null; @endphp
                <div class="rounded-xl bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold">
                                {{ $recommendation->activity?->name ?? ucfirst($recommendation->category->value) }}
                            </p>
                            <p class="mt-1 flex items-center gap-2 text-xs text-gray-500">
                                <x-icon name="calendar" class="h-4 w-4 shrink-0 text-amber-800" />
                                {{ $recommendation->suggested_start->format('l, M j, g:i A') }}
                                &ndash;
                                {{ $recommendation->suggested_end->format('g:i A') }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full bg-brand/30 px-3 py-1 text-xs font-semibold">
                            {{ round($recommendation->confidence_score * 100) }}% confidence
                        </span>
                    </div>

                    @if ($conflicts)
                        <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
                            <p class="font-semibold">This clashes with your existing schedule:</p>
                            <ul class="mt-1 list-disc pl-4">
                                @foreach ($conflicts as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="mt-3 flex gap-2">
                        <form method="POST" action="{{ route('recommendations.accept', $recommendation) }}">
                            @csrf
                            @if ($conflicts)
                                <input type="hidden" name="confirmed" value="1">
                            @endif
                            <button type="submit" class="rounded-md bg-gray-900 px-4 py-1.5 text-xs font-semibold text-white">
                                {{ $conflicts ? 'Confirm anyway' : 'Accept suggestion' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('recommendations.dismiss', $recommendation) }}">
                            @csrf
                            <button type="submit" class="rounded-md border border-gray-300 px-4 py-1.5 text-xs">Dismiss</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
