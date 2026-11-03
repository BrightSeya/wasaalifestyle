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

        <section class="rounded-lg border border-gray-200 bg-white p-5" aria-labelledby="model-heading">
            <div>
                <h2 id="model-heading" class="text-base font-semibold">Slot model evaluation</h2>
                <p class="mt-1 text-sm text-gray-600">Read from the recommender’s model card and baseline results.</p>
            </div>

            @if (! $metrics['available'])
                <p role="status" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ $metrics['message'] }}
                </p>
            @else
                <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-600">Cross-validation F1</dt>
                        <dd class="mt-1 text-xl font-semibold">{{ number_format($metrics['cross_validation_f1'], 4) }}</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-600">Validation F1</dt>
                        <dd class="mt-1 text-xl font-semibold">{{ number_format($metrics['validation_f1'], 4) }}</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-600">Random Forest Hit Rate@3</dt>
                        <dd class="mt-1 text-xl font-semibold">{{ number_format($metrics['hit_rate_at_3'] * 100, 1) }}%</dd>
                    </div>
                    <div class="rounded-md bg-gray-50 p-4">
                        <dt class="text-sm text-gray-600">Random Forest MAE</dt>
                        <dd class="mt-1 text-xl font-semibold">{{ number_format($metrics['mae_hours'], 2) }} hours</dd>
                    </div>
                </dl>

                <div class="mt-6 overflow-x-auto">
                    <h3 class="mb-2 text-sm font-semibold">Model comparison</h3>
                    <table class="w-full min-w-[520px] text-left text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase text-gray-500">
                            <tr><th class="py-3 pr-4">Model</th><th class="py-3 pr-4">F1</th><th class="py-3 pr-4">Hit Rate@3</th><th class="py-3">MAE (hours)</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($metrics['models'] as $model)
                                <tr @class(['bg-brand/10 font-semibold' => $model['label'] === 'Random Forest'])>
                                    <th scope="row" class="py-3 pr-4">{{ $model['label'] }}</th>
                                    <td class="py-3 pr-4">{{ number_format($model['f1'], 4) }}</td>
                                    <td class="py-3 pr-4">{{ number_format($model['hit_rate_at_3'] * 100, 1) }}%</td>
                                    <td class="py-3">{{ number_format($model['mae_hours'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
