<x-layouts.guest title="Log in | WasaaLifestyle" heading="Welcome back" subtitle="Pick up where you left off. Your next good week starts with a plan.">
    @if (session('status'))
        <p role="status" class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm leading-5 text-green-800">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   placeholder="name@domain.com"
                   class="form-input">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="form-input">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" name="remember" class="h-4 w-4 rounded border-gray-300 text-amber-700 focus:ring-brand"> Remember me
        </label>

        <button type="submit" class="btn-primary w-full">
            Log in <x-icon name="arrow-right" class="h-4 w-4" />
        </button>

        <p class="border-t border-gray-100 pt-5 text-center text-sm text-gray-600">
            New to Wasaa? <a href="{{ route('register') }}" class="font-semibold text-amber-800 underline decoration-amber-500/50 underline-offset-4 hover:text-amber-900">Create an account</a>
        </p>
    </form>
</x-layouts.guest>
