<x-layouts.app title="Notifications | WasaaLifestyle" heading="Notifications">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-600">Your latest schedule and account notifications.</p>
        @if (auth()->user()->notifications()->where('is_read', false)->exists())
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button type="submit" class="text-sm font-semibold text-gray-700 underline">Mark all as read</button>
            </form>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <p class="rounded-xl bg-white p-6 text-sm text-gray-500 shadow-sm">You have no notifications.</p>
    @else
        <div class="space-y-3">
            @foreach ($notifications as $notification)
                <article @class([
                    'rounded-xl border p-4 shadow-sm',
                    'border-brand/50 bg-white' => ! $notification->is_read,
                    'border-gray-200 bg-gray-50' => $notification->is_read,
                ])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ $notification->isCommitmentRiskAlert() ? 'Commitment risk alert' : str($notification->type)->replace('_', ' ')->title() }}
                                @unless ($notification->is_read)
                                    <span class="ml-2 rounded-full bg-brand/30 px-2 py-0.5 normal-case">Unread</span>
                                @endunless
                            </p>
                            <p class="mt-1 text-sm text-gray-800">{{ $notification->message }}</p>
                            @if ($notification->schedule)
                                <a class="mt-1 inline-block text-xs text-gray-600 underline"
                                   href="{{ route('schedules.edit', $notification->schedule) }}">
                                    {{ $notification->schedule->title }}
                                </a>
                            @endif
                            <time class="mt-2 block text-xs text-gray-500" datetime="{{ $notification->created_at->toIso8601String() }}">
                                {{ $notification->created_at->format('M j, Y g:i A') }}
                            </time>
                        </div>
                        @if ($notification->isCommitmentRiskAlert())
                            <div class="flex flex-wrap gap-3">
                                <form method="POST" action="{{ route('notifications.alternatives', $notification) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold text-brand-dark underline">Find a better time</button>
                                </form>
                                <form method="POST" action="{{ route('notifications.dismiss', $notification) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="text-xs font-semibold text-gray-700 underline">Dismiss</button>
                                </form>
                            </div>
                        @elseif (! $notification->is_read)
                            <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-xs font-semibold text-gray-700 underline">Mark as read</button>
                            </form>
                        @endunless
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    @endif
</x-layouts.app>
