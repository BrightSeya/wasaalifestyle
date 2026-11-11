<x-layouts.guest title="Forgot password | WasaaLifestyle" heading="Reset your password" subtitle="Enter the email address on your account and we’ll send a reset link if it matches an account.">
    @if (session('status'))
        <p role="status" class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm leading-5 text-green-800">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                   placeholder="name@domain.com"
                   class="form-input">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn-primary w-full">
            Send reset link <x-icon name="arrow-right" class="h-4 w-4" />
        </button>

        <p class="border-t border-gray-100 pt-5 text-center text-sm text-gray-600">
            Remember your password? <a href="{{ route('login') }}" class="font-semibold text-amber-800 underline decoration-amber-500/50 underline-offset-4 hover:text-amber-900">Log in</a>
        </p>
    </form>
</x-layouts.guest>
