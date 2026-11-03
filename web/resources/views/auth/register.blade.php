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

        <button type="submit" class="btn-primary w-full">
            Create account <x-icon name="arrow-right" class="h-4 w-4" />
        </button>

        <p class="border-t border-gray-100 pt-5 text-center text-sm text-gray-600">
            Already have an account? <a href="{{ route('login') }}" class="font-semibold text-amber-800 underline decoration-amber-500/50 underline-offset-4 hover:text-amber-900">Log in</a>
        </p>
    </form>
</x-layouts.guest>
