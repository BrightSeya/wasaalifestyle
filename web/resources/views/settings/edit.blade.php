@php
    $input = 'form-input';
    $labels = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
    $settingsUser = auth()->user();
    $settingsInitials = collect(explode(' ', trim($settingsUser->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp

<x-layouts.app title="Profile & Settings | WasaaLifestyle" heading="Profile & Settings">
    <div class="settings-page mx-auto max-w-5xl space-y-5">
        <div class="mb-7 max-w-2xl">
            <p class="text-xs font-bold uppercase tracking-[.16em] text-amber-800">Make it yours</p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 sm:text-3xl">Your profile, your rhythm.</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600">Update how Wasaa recognizes you, shape your planning preferences, and manage account access.</p>
        </div>

        <section class="app-card overflow-hidden rounded-3xl border border-gray-200/80 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-7">
                <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-100 text-amber-900"><x-icon name="user" class="h-[18px] w-[18px]" /></span><div><h2 class="text-sm font-semibold text-gray-900">Personal details</h2><p class="mt-0.5 text-xs text-gray-500">Your name and photo appear in your account menu.</p></div></div>
            </div>

            <form method="POST" action="{{ route('settings.profile') }}" enctype="multipart/form-data" class="space-y-5 p-5 sm:p-7">
                @csrf
                @method('PUT')

                <div class="profile-photo-panel flex flex-col gap-4 rounded-2xl bg-[#faf9f6] p-4 sm:flex-row sm:items-center sm:gap-5">
                    <div class="relative h-[4.5rem] w-[4.5rem] shrink-0">
                        @if ($settingsUser->profile_photo_path)
                            <img data-avatar-preview src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($settingsUser->profile_photo_path) }}" alt="Profile photo preview" class="h-full w-full rounded-2xl object-cover ring-1 ring-black/5">
                            <span data-avatar-fallback class="hidden flex h-full w-full items-center justify-center rounded-2xl bg-amber-100 text-lg font-bold text-amber-950">{{ $settingsInitials }}</span>
                        @else
                            <img data-avatar-preview alt="Profile photo preview" class="hidden h-full w-full rounded-2xl object-cover ring-1 ring-black/5">
                            <span data-avatar-fallback class="flex h-full w-full items-center justify-center rounded-2xl bg-gradient-to-br from-amber-100 to-orange-200 text-lg font-bold text-amber-950">{{ $settingsInitials }}</span>
                        @endif
                        <span class="profile-photo-badge absolute -bottom-2 -right-2 flex h-7 w-7 items-center justify-center rounded-xl border-2 border-[#faf9f6] bg-gray-900 text-white"><x-icon name="camera" class="h-3.5 w-3.5" /></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <label for="profile_photo" class="block text-sm font-semibold text-gray-900">Profile photo</label>
                        <p class="mt-1 text-xs leading-5 text-gray-500">Choose a clear square image. JPG, PNG, or WebP up to 2 MB.</p>
                        <input id="profile_photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" data-avatar-input class="mt-3 block w-full max-w-lg cursor-pointer text-xs text-gray-600 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-xs file:font-semibold file:text-gray-700 file:shadow-sm hover:file:bg-amber-50">
                        @error('profile_photo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @if ($settingsUser->profile_photo_path)
                            <label class="mt-3 inline-flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="remove_profile_photo" value="1" @checked(old('remove_profile_photo')) class="rounded border-gray-300 text-amber-700 focus:ring-amber-600"> Remove current photo</label>
                        @endif
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-xs font-semibold text-gray-700">Full name</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $settingsUser->name) }}" required class="{{ $input }}">
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email" class="block text-xs font-semibold text-gray-700">Email address</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $settingsUser->email) }}" required class="{{ $input }}">
                        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <button type="submit" class="btn-primary">Save profile</button>
            </form>
        </section>

        <details data-settings-disclosure class="app-card group rounded-2xl border border-gray-200/80 bg-white p-5 shadow-sm sm:p-6" @if ($errors->has('current_password') || $errors->has('new_password')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                <span>Change password</span>
            </summary>
            <p class="mb-4 mt-2 text-sm text-gray-500">Verify your current password before choosing a new one.</p>

            <form method="POST" action="{{ route('settings.password') }}" class="mt-4 space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="current_password" class="block text-xs text-gray-600">Current Password</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="{{ $input }} sm:max-w-md">
                    @error('current_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="new_password" class="block text-xs text-gray-600">New Password</label>
                    <input id="new_password" name="new_password" type="password" required autocomplete="new-password" class="{{ $input }} sm:max-w-md">
                    @error('new_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="new_password_confirmation" class="block text-xs text-gray-600">Confirm New Password</label>
                    <input id="new_password_confirmation" name="new_password_confirmation" type="password" required autocomplete="new-password" class="{{ $input }} sm:max-w-md">
                </div>

                <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Update Password</button>
            </form>
        </details>

        <section class="app-card rounded-2xl border border-gray-200/80 bg-white p-5 sm:p-6" aria-labelledby="calendar-connection-heading">
            <h2 id="calendar-connection-heading" class="mb-1 text-sm font-semibold text-gray-700">Google Calendar</h2>
            <p class="mb-4 text-xs text-gray-500">Connect Google Calendar and Tasks with read-only access. Wasaa also reads the connected Google account email to identify the account; it will not change items in Google.</p>

            @if (session('calendar_error'))
                <p role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ session('calendar_error') }}</p>
            @endif

            @if ($calendarConnection)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm text-green-800">Google Calendar is connected.</p>
                        @if ($calendarConnection->google_account_email)
                            <p class="mt-1 text-xs text-gray-600">Google account: {{ $calendarConnection->google_account_email }}</p>
                        @endif
                        @unless ($calendarTasksAuthorized)
                            <p class="mt-1 text-xs text-amber-800">Reconnect to grant read-only Google Tasks access before importing tasks.</p>
                        @endunless
                        @unless ($calendarAccountEmailAuthorized && $calendarConnection->google_account_email)
                            <p class="mt-1 text-xs text-amber-800">Reconnect to share the connected Google account email with Wasaa.</p>
                        @endunless
                    </div>
                    @if (! $calendarTasksAuthorized || ! $calendarAccountEmailAuthorized || ! $calendarConnection->google_account_email)
                        <a href="{{ route('settings.google-calendar.connect') }}" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Reconnect Google Calendar</a>
                    @endif
                    <form method="POST" action="{{ route('settings.google-calendar.disconnect') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold hover:bg-gray-50">Disconnect Google Calendar</button>
                    </form>
                </div>
            @elseif ($calendarConfigured)
                <a href="{{ route('settings.google-calendar.connect') }}" class="inline-flex rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Connect Google Calendar</a>
            @else
                <p role="status" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Google Calendar connection is not configured on this server. Contact the administrator.</p>
            @endif
        </section>

        <form method="POST" action="{{ route('settings.preferences') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="mb-1 text-sm font-semibold text-gray-700">Weekly Availability Windows</h2>
                <p class="mb-4 text-xs text-gray-500">Choose the times you are usually free. Add more than one range on a day if you need a break between them.</p>

                <div class="mb-5 max-w-md">
                    @php
                        $selectedTimezone = old('timezone', auth()->user()->timezone ?? 'Africa/Nairobi');
                        $timezoneOptions = config('timezones.common', []);
                        if (in_array($selectedTimezone, timezone_identifiers_list(), true) && ! in_array($selectedTimezone, $timezoneOptions, true)) {
                            $timezoneOptions[] = $selectedTimezone;
                        }
                    @endphp
                    <label for="timezone" class="block text-xs font-medium text-gray-700">Your time zone</label>
                    <select id="timezone" name="timezone" required class="mt-1 w-full rounded-md border-gray-300 text-sm">
                        @foreach ($timezoneOptions as $timezoneOption)
                            <option value="{{ $timezoneOption }}" @selected($selectedTimezone === $timezoneOption)>{{ str_replace('_', ' ', $timezoneOption) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Availability, schedules, and recommendations use this time zone.</p>
                    @error('timezone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($labels as $day => $label)
                        @php($dayRanges = old("availability.$day", $preferences->availability[$day] ?? []))
                        <section class="rounded-xl border border-gray-200 p-3" aria-labelledby="availability-{{ $day }}-label">
                            <h3 id="availability-{{ $day }}-label" class="mb-2 text-sm font-semibold text-gray-800">{{ $label }}</h3>
                            <div class="space-y-2" data-availability-ranges="{{ $day }}">
                                @foreach ($dayRanges as $index => $range)
                                    <div class="flex flex-wrap items-end gap-2" data-availability-range>
                                        <div>
                                            <label class="block text-[11px] text-gray-600" for="availability-{{ $day }}-{{ $index }}-start">From</label>
                                            <input id="availability-{{ $day }}-{{ $index }}-start" type="time" step="900" required name="availability[{{ $day }}][{{ $index }}][start]" value="{{ $range['start'] ?? '' }}" class="rounded-md border-gray-300 text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-[11px] text-gray-600" for="availability-{{ $day }}-{{ $index }}-end">To</label>
                                            <input id="availability-{{ $day }}-{{ $index }}-end" type="time" step="900" required name="availability[{{ $day }}][{{ $index }}][end]" value="{{ $range['end'] ?? '' }}" class="rounded-md border-gray-300 text-sm">
                                        </div>
                                        <button type="button" data-remove-availability-range class="rounded-md px-2 py-2 text-xs font-medium text-gray-600 hover:bg-gray-100">Remove</button>
                                    </div>
                                    @foreach ($errors->get("availability.$day.$index.start") as $message)<p class="text-xs text-red-600">{{ $message }}</p>@endforeach
                                    @foreach ($errors->get("availability.$day.$index.end") as $message)<p class="text-xs text-red-600">{{ $message }}</p>@endforeach
                                @endforeach
                            </div>
                            @if (count($dayRanges) === 0)<p class="mt-2 text-xs text-gray-500" data-no-availability>No available hours set.</p>@endif
                            <button type="button" data-add-availability-range="{{ $day }}" class="mt-2 rounded-md border border-gray-300 px-2.5 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Add time range</button>
                        </section>
                    @endforeach
                </div>
                <template data-availability-range-template>
                    <div class="flex flex-wrap items-end gap-2" data-availability-range>
                        <div><label class="block text-[11px] text-gray-600">From</label><input type="time" step="900" required data-range-start class="rounded-md border-gray-300 text-sm"></div>
                        <div><label class="block text-[11px] text-gray-600">To</label><input type="time" step="900" required data-range-end class="rounded-md border-gray-300 text-sm"></div>
                        <button type="button" data-remove-availability-range class="rounded-md px-2 py-2 text-xs font-medium text-gray-600 hover:bg-gray-100">Remove</button>
                    </div>
                </template>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
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

            <section class="rounded-2xl border border-brand/30 bg-brand/15 p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-700">Notification Alerts</h2>

                <div class="space-y-3">
                    <label class="flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-3">
                        <span>
                            <span class="block text-sm font-medium">Personalize time recommendations</span>
                            <span class="block text-xs leading-5 text-gray-500">Use your completed and missed activity and schedule outcomes from the past year for recommendations just for you. After enough history is available, Wasaa tests a personal model against the general model and uses it only if it performs better. Until then, your history adjusts the general recommendations. Turn this off at any time.</span>
                        </span>
                        <input type="checkbox" name="personalize_recommendations" value="1"
                               @checked(old('personalize_recommendations', $preferences->personalizeRecommendations))
                               class="h-5 w-9 rounded-full border-gray-300 text-brand focus:ring-brand">
                    </label>

                    <label class="flex items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white p-3">
                        <span>
                            <span class="block text-sm font-medium">Help improve recommendations for everyone</span>
                            <span class="block text-xs leading-5 text-gray-500">Share anonymized counts of completed and missed outcomes by activity type, weekday, and hour to help improve the shared model. Your name, activity titles, and calendar details are not shared. You can turn this off at any time.</span>
                        </span>
                        <input type="checkbox" name="contribute_shared_recommendations" value="1"
                               @checked(old('contribute_shared_recommendations', $settingsUser->shared_recommendation_consent_at !== null))
                               class="h-5 w-9 rounded-full border-gray-300 text-brand focus:ring-brand">
                    </label>

                    @foreach ([
                        'notify_conflicts' => ['Schedule Conflict Alerts', 'Notify when double-booking happens', $preferences->notifyConflicts],
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

            <button type="submit" class="btn-primary">Save preferences</button>
        </form>

        <details data-settings-disclosure class="group rounded-xl border border-red-200 bg-red-50 p-6 shadow-sm" @if ($errors->has('delete_current_password') || $errors->has('confirmation') || $errors->has('account')) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-red-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                <span>Delete account</span>
            </summary>
            <p class="mb-4 mt-2 text-sm text-red-800">Permanently delete your account, schedules, activities, recommendations, notifications, and connected calendar credentials. This cannot be undone.</p>

            <form method="POST" action="{{ route('settings.account.destroy') }}" class="mt-4 space-y-4" data-confirm="Permanently delete your account and all associated data? This cannot be undone.">
                @csrf
                @method('DELETE')

                <div>
                    <label for="delete_confirmation" class="block text-xs text-red-900">Type DELETE to confirm</label>
                    <input id="delete_confirmation" name="confirmation" type="text" required autocomplete="off" class="mt-1 w-full rounded-md border border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:outline-none sm:max-w-xs">
                    @error('confirmation') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="delete_current_password" class="block text-xs text-red-900">Current Password</label>
                    <input id="delete_current_password" name="delete_current_password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded-md border border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:outline-none sm:max-w-xs">
                    @error('delete_current_password') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                    @error('account') <p role="alert" class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Permanently Delete Account</button>
            </form>
        </details>
    </div>
<script>
    (() => {
        const template = document.querySelector('[data-availability-range-template]');
        if (!template) return;

        document.querySelectorAll('[data-add-availability-range]').forEach(button => {
            button.addEventListener('click', () => {
                const day = button.dataset.addAvailabilityRange;
                const list = document.querySelector(`[data-availability-ranges="${day}"]`);
                const indexes = [...list.querySelectorAll('[name]')].map(input => Number(input.name.match(/\[(\d+)\]/)?.[1] ?? -1));
                const next = Math.max(-1, ...indexes) + 1;
                const row = template.content.firstElementChild.cloneNode(true);
                const start = row.querySelector('[data-range-start]');
                const end = row.querySelector('[data-range-end]');
                start.name = `availability[${day}][${next}][start]`;
                start.id = `availability-${day}-${next}-start`;
                start.previousElementSibling.htmlFor = start.id;
                end.name = `availability[${day}][${next}][end]`;
                end.id = `availability-${day}-${next}-end`;
                end.previousElementSibling.htmlFor = end.id;
                list.append(row);
                list.parentElement.querySelector('[data-no-availability]')?.remove();
            });
        });

        document.addEventListener('click', event => {
            const button = event.target.closest('[data-remove-availability-range]');
            if (!button) return;
            const list = button.closest('[data-availability-ranges]');
            button.closest('[data-availability-range]').remove();
            if (!list.querySelector('[data-availability-range]')) {
                const message = document.createElement('p');
                message.className = 'mt-2 text-xs text-gray-500';
                message.dataset.noAvailability = '';
                message.textContent = 'No available hours set.';
                list.after(message);
            }
        });
    })();
</script>
</x-layouts.app>
