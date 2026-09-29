<x-layouts.guest title="Create account | WasaaLifestyle" subtitle="Create Your Account">
    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-xs text-gray-600">Full Name</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name"
                   class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="block text-xs text-gray-600">Email Address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                   placeholder="name@domain.com"
                   class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-xs text-gray-600">Password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-xs text-gray-600">Confirm Password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
        </div>

        <button type="submit" class="w-full rounded-md bg-brand py-2 text-sm font-semibold hover:bg-brand-dark">
            Create Account
        </button>

        <p class="text-center text-xs text-gray-600">
            Already have an account? <a href="{{ route('login') }}" class="font-semibold text-gray-900 underline">Log In</a>
        </p>
    </form>
</x-layouts.guest>
