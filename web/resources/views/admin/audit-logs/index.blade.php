<x-layouts.app title="System Logs | WasaaLifestyle" heading="System Audit Logs">
    @php($actionLabels = [
        'user.created' => 'Account created',
        'user.suspended' => 'Account suspended',
        'user.reactivated' => 'Account reactivated',
        'user.password_reset_requested' => 'Password reset requested',
    ])

    <section class="rounded-lg border border-gray-200 bg-white p-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Administrator activity</h2>
                <p class="mt-1 text-sm text-gray-600">Account management events, newest first. {{ $logs->total() }} event(s).</p>
            </div>
            <form method="GET" action="{{ route('admin.audit-logs.index') }}" class="flex items-end gap-2">
                <div>
                    <label for="action" class="block text-xs text-gray-600">Filter by action</label>
                    <select id="action" name="action" class="mt-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm">
                        <option value="">All actions</option>
                        @foreach ($actionLabels as $value => $label)
                            <option value="{{ $value }}" @selected($action === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">Filter</button>
            </form>
        </div>
        @error('action') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[700px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500">
                    <tr><th class="py-3 pr-4">When</th><th class="py-3 pr-4">Administrator</th><th class="py-3 pr-4">Action</th><th class="py-3">Affected account</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap py-3 pr-4" title="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('M j, Y g:i A') }}</td>
                            <td class="py-3 pr-4">
                                @if ($log->administrator)
                                    <span class="block">{{ $log->administrator->name }}</span>
                                    <span class="text-xs text-gray-500">{{ $log->administrator->email }}</span>
                                @else
                                    <span class="text-gray-500">Deleted account</span>
                                @endif
                            </td>
                            <td class="py-3 pr-4">{{ $actionLabels[$log->action] ?? $log->action }}</td>
                            <td class="py-3">
                                @if ($log->affectedUser)
                                    <span class="block">{{ $log->affectedUser->name }}</span>
                                    <span class="text-xs text-gray-500">{{ $log->affectedUser->email }}</span>
                                @else
                                    <span class="text-gray-500">Deleted account</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-gray-500">No audit events found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $logs->links() }}</div>
    </section>
</x-layouts.app>
