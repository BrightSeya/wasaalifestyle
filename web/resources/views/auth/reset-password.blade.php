<x-layouts.guest title="Set Password | WasaaLifestyle" subtitle="Choose a secure password for your account">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="block text-xs text-gray-600">Email Address</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username" class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="block text-xs text-gray-600">New Password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password_confirmation" class="block text-xs text-gray-600">Confirm New Password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm">
        </div>
        <button type="submit" class="w-full rounded-md bg-brand py-2 text-sm font-semibold hover:bg-brand-dark">Set password</button>
    </form>
</x-layouts.guest>
