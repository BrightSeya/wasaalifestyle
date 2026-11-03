<x-layouts.guest title="Set Password | WasaaLifestyle" heading="Choose a password" subtitle="Set a secure password to finish setting up your Wasaa account.">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username" class="form-input">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="form-input">
            @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="form-input">
        </div>
        <button type="submit" class="btn-primary w-full">Set password <x-icon name="arrow-right" class="h-4 w-4" /></button>
    </form>
</x-layouts.guest>
