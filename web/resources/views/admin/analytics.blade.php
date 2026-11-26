<x-layouts.app title="Performance | WasaaLifestyle" heading="Platform Analytics">
    <div class="space-y-6">
        <section aria-labelledby="usage-heading">
            <div class="mb-3">
                <h2 id="usage-heading" class="text-base font-semibold">Platform usage</h2>
                <p class="mt-1 text-sm text-gray-600">Aggregate totals only; individual account activity is not shown here.</p>
            </div>
            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    'Registered users' => $counts['users'],
                    'Active accounts' => $counts['active_users'],
                    'Suspended accounts' => $counts['suspended_users'],
                    'Schedules' => $counts['schedules'],
                    'Activities' => $counts['activities'],
                ] as $label => $value)
                    <div class="rounded-lg border border-gray-200 bg-white p-5">
                        <dt class="text-sm text-gray-600">{{ $label }}</dt>
                        <dd class="mt-2 text-2xl font-semibold">{{ number_format($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5" aria-labelledby="service-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="service-heading" class="text-base font-semibold">Slot recommender service</h2>
                    <p class="mt-1 text-sm text-gray-600">Health endpoint status</p>
                </div>
                @if ($health['reachable'])
                    <span class="rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800">
                        Reachable (HTTP {{ $health['http_status'] }})
                    </span>
                @else
                    <span class="rounded-full bg-red-100 px-3 py-1 text-sm font-medium text-red-800">Unreachable</span>
                @endif
            </div>
            @if ($health['reachable'])
                <p class="mt-3 text-sm text-gray-700">
                    Model status:
                    @if ($health['model_loaded'] === true)
                        <span class="font-medium text-green-800">Loaded</span>
                    @elseif ($health['model_loaded'] === false)
                        <span class="font-medium text-amber-800">Not loaded</span>
                    @else
                        <span class="font-medium text-gray-600">Not reported by the health endpoint</span>
                    @endif
                </p>
            @endif
        </section>

    </div>
</x-layouts.app>
