@php
    $input = 'mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none';
    $labels = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
@endphp

<x-layouts.app title="Profile & Settings | WasaaLifestyle" heading="Profile & Settings">
    <div class="max-w-3xl space-y-6">
        <section class="rounded-xl bg-white p-6 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold text-gray-700">Profile</h2>

            <form method="POST" action="{{ route('settings.profile') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="name" class="block text-xs text-gray-600">Full Name</label>
                    <input id="name" name="name" type="text" value="{{ old('name', auth()->user()->name) }}" required class="{{ $input }}">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-xs text-gray-600">Email Address</label>
                    <input id="email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}" required class="{{ $input }}">
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 border-t border-gray-100 pt-4 sm:grid-cols-3">
                    <div>
                        <label for="current_password" class="block text-xs text-gray-600">Current Password</label>
                        <input id="current_password" name="current_password" type="password" class="{{ $input }}">
                        @error('current_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="new_password" class="block text-xs text-gray-600">New Password</label>
                        <input id="new_password" name="new_password" type="password" class="{{ $input }}">
                        @error('new_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="new_password_confirmation" class="block text-xs text-gray-600">Confirm New Password</label>
                        <input id="new_password_confirmation" name="new_password_confirmation" type="password" class="{{ $input }}">
                    </div>
                </div>

                <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Save Profile</button>
            </form>
        </section>

        <form method="POST" action="{{ route('settings.preferences') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-1 text-sm font-semibold text-gray-700">Weekly Availability Windows</h2>
                <p class="mb-4 text-xs text-gray-500">Configure when Wasaa may schedule tasks for you.</p>

                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-1"></th>
                            <th class="py-1">Morning</th>
                            <th class="py-1">Afternoon</th>
                            <th class="py-1">Evening</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($labels as $day => $label)
                            <tr>
                                <td class="py-1 font-medium">{{ $label }}</td>
                                @foreach (['morning', 'afternoon', 'evening'] as $period)
                                    <td class="py-1">
                                        <input type="checkbox" name="availability[{{ $day }}][{{ $period }}]" value="1"
                                               @checked(old("availability.$day.$period", $preferences->availability[$day][$period]))
                                               class="rounded border-gray-300 text-brand focus:ring-brand">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">Productivity Preferences</h2>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="peak_start_hour" class="block text-xs text-gray-600">Peak Start Hour</label>
                        <input id="peak_start_hour" name="peak_start_hour" type="number" min="0" max="23"
                               value="{{ old('peak_start_hour', $preferences->peakStartHour) }}" class="{{ $input }}">
                        @error('peak_start_hour') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="peak_end_hour" class="block text-xs text-gray-600">Peak End Hour</label>
                        <input id="peak_end_hour" name="peak_end_hour" type="number" min="0" max="23"
                               value="{{ old('peak_end_hour', $preferences->peakEndHour) }}" class="{{ $input }}">
                        @error('peak_end_hour') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="preferred_break_minutes" class="block text-xs text-gray-600">Preferred Break Duration (minutes)</label>
                        <input id="preferred_break_minutes" name="preferred_break_minutes" type="number" min="5" max="120"
                               value="{{ old('preferred_break_minutes', $preferences->preferredBreakMinutes) }}" class="{{ $input }}">
                        @error('preferred_break_minutes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section class="rounded-xl bg-brand/20 p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">Notification Alerts</h2>

                <div class="space-y-3">
                    @foreach ([
                        'notify_conflicts' => ['Schedule Conflict Alerts', 'Notify when double-booking happens', $preferences->notifyConflicts],
                        'notify_daily_summary' => ['Daily Schedule Summary', 'Send morning email preview of the day', $preferences->notifyDailySummary],
                        'notify_recommendations' => ['Recommendation Alerts', 'When optimal slot improvements are found', $preferences->notifyRecommendations],
                    ] as $name => [$title, $description, $current])
                        <label class="flex items-center justify-between gap-4">
                            <span>
                                <span class="block text-sm font-medium">{{ $title }}</span>
                                <span class="block text-xs text-gray-500">{{ $description }}</span>
                            </span>
                            <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $current))
                                   class="h-5 w-9 rounded-full border-gray-300 text-brand focus:ring-brand">
                        </label>
                    @endforeach
                </div>
            </section>

            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Save Preferences</button>
        </form>
    </div>
</x-layouts.app>
