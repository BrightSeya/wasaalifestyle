<x-layouts.app title="Admin | WasaaLifestyle" heading="Admin Portal">
    <section class="rounded-lg border border-gray-200 bg-white p-5">
        <h2 class="text-base font-semibold">Account administration</h2>
        <p class="mt-1 text-sm text-gray-600">View registered accounts, manage access, and send secure password setup or reset links.</p>
        <a href="{{ route('admin.users.index') }}" class="mt-4 inline-flex rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">Manage users</a>
    </section>
</x-layouts.app>
