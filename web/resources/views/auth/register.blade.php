<x-layouts.guest title="Create account | WasaaLifestyle" heading="Create Your Account" subtitle="Start with your routines and the time you have. You can shape the rest as you go.">
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium text-gray-700">Full name</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name"
                   class="form-input">
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                   placeholder="name@domain.com"
                   class="form-input">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="form-input">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="form-input">
        </div>

        <label for="commitment_risk_consent" class="flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            <input id="commitment_risk_consent" name="commitment_risk_consent" type="checkbox" value="1"
                   @checked(old('commitment_risk_consent')) class="mt-1 rounded border-gray-300 text-amber-700 focus:ring-amber-600">
            <span>
                <span class="font-semibold">Enable no-show predictions and alerts</span>
                <span class="mt-1 block text-xs leading-5 text-gray-600">I agree that Wasaa may use my recorded completed and missed outcomes to estimate no-show risk, send in-app reminders, and personalize my future estimates. My outcome history is used for my estimates, not pooled into other users' predictions. The initial model uses synthetic booking data and may be inaccurate. I can turn this off in Settings at any time. Skipped and cancelled activities are not used as missed outcomes.</span>
            </span>
        </label>
        @error('commitment_risk_consent') <p class="-mt-3 text-xs text-red-600">{{ $message }}</p> @enderror

        <label for="recommendation_personalization_consent" class="flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            <input id="recommendation_personalization_consent" name="recommendation_personalization_consent" type="checkbox" value="1"
                   @checked(old('recommendation_personalization_consent')) class="mt-1 rounded border-gray-300 text-amber-700 focus:ring-amber-600">
            <span>
                <span class="font-semibold">Personalize my time recommendations</span>
                <span class="mt-1 block text-xs leading-5 text-gray-600">I agree that Wasaa may use my completed and missed activity and schedule outcomes from the past year to adjust my future time recommendations. This history is used for my recommendations and is not pooled with other users. I can change this choice in Settings at any time. If I leave this unchecked, I can still receive general recommendations.</span>
            </span>
        </label>
        @error('recommendation_personalization_consent') <p class="-mt-3 text-xs text-red-600">{{ $message }}</p> @enderror

        <button type="submit" class="btn-primary w-full">
            Create account <x-icon name="arrow-right" class="h-4 w-4" />
        </button>

        <p class="border-t border-gray-100 pt-5 text-center text-sm text-gray-600">
            Already have an account? <a href="{{ route('login') }}" class="font-semibold text-amber-800 underline decoration-amber-500/50 underline-offset-4 hover:text-amber-900">Log in</a>
        </p>
    </form>
</x-layouts.guest>
