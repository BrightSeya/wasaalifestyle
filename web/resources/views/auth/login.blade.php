<x-layouts.guest title="Log in | WasaaLifestyle" subtitle="Your AI-Powered Lifestyle Scheduler">
    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-xs text-gray-600">Email Address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   placeholder="name@domain.com"
                   class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-xs text-gray-600">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-xs text-gray-600">
            <input type="checkbox" name="remember" class="rounded border-gray-300"> Remember me
        </label>

        <button type="submit" class="w-full rounded-md bg-brand py-2 text-sm font-semibold hover:bg-brand-dark">
            Log In
        </button>

        <p class="text-center text-xs text-gray-600">
            Don't have an account? <a href="{{ route('register') }}" class="font-semibold text-gray-900 underline">Register</a>
        </p>
    </form>
</x-layouts.guest>
