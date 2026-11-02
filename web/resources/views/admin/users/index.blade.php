<x-layouts.app title="User Management | WasaaLifestyle" heading="User Management">
    <div class="space-y-8">
        <section class="rounded-lg border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold">Create an account</h2>
            <p class="mt-1 text-sm text-gray-600">A secure password setup link will be emailed to the new user.</p>
            <form method="POST" action="{{ route('admin.users.store') }}" class="mt-4 grid gap-4 md:grid-cols-4">
                @csrf
                <div>
                    <label for="name" class="block text-xs text-gray-600">Name</label>
                    <input id="name" name="name" value="{{ old('name') }}" required maxlength="255" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="email" class="block text-xs text-gray-600">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="role" class="block text-xs text-gray-600">Role</label>
                    <select id="role" name="role" required class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm">
                        <option value="user" @selected(old('role', 'user') === 'user')>User</option>
                        <option value="admin" @selected(old('role') === 'admin')>Administrator</option>
                    </select>
                    @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Create account</button>
                </div>
            </form>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold">Registered users</h2>
                    <p class="mt-1 text-sm text-gray-600">Search by name or email. {{ $users->total() }} account(s).</p>
                </div>
                <form method="GET" action="{{ route('admin.users.index') }}" class="flex gap-2">
                    <label for="search" class="sr-only">Search users by name or email</label>
                    <input id="search" name="search" value="{{ $search }}" placeholder="Name or email" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                    <button class="rounded-md border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">Search</button>
                </form>
            </div>
            @error('search') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500">
                        <tr><th class="py-3 pr-4">Name</th><th class="py-3 pr-4">Email</th><th class="py-3 pr-4">Role</th><th class="py-3 pr-4">Status</th><th class="py-3 pr-4">Created</th><th class="py-3">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($users as $user)
                            <tr>
                                <td class="py-3 pr-4 font-medium">{{ $user->name }}</td>
                                <td class="py-3 pr-4">{{ $user->email }}</td>
                                <td class="py-3 pr-4">{{ $user->role === 'admin' ? 'Administrator' : 'User' }}</td>
                                <td class="py-3 pr-4">{{ ucfirst($user->status) }}</td>
                                <td class="py-3 pr-4">{{ $user->created_at->format('M j, Y') }}</td>
                                <td class="space-y-2 py-3">
                                    @if (! $user->is(auth()->user()))
                                        <form method="POST" action="{{ route('admin.users.status', $user) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="{{ $user->status === 'active' ? 'suspended' : 'active' }}">
                                            <button class="text-xs font-semibold underline" type="submit">{{ $user->status === 'active' ? 'Suspend' : 'Reactivate' }}</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-gray-400">Current account</span>
                                    @endif
                                    <form method="POST" action="{{ route('admin.users.password-reset', $user) }}">
                                        @csrf
                                        <button class="text-xs font-semibold underline" type="submit">Send password reset</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-gray-500">No users match this search.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $users->links() }}</div>
        </section>
    </div>
</x-layouts.app>
