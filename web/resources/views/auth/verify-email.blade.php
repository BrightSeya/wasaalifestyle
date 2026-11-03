<x-layouts.app title="Verify your email | WasaaLifestyle" heading="Verify your email">
    <section class="mx-auto max-w-xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold">Check your inbox</h2>
        <p class="mt-2 text-sm text-gray-600">
            Verify {{ auth()->user()->email }} to access your schedule and account data.
        </p>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-5">
            @csrf
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold hover:bg-brand-dark">
                Resend verification email
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="text-sm text-gray-600 underline hover:text-gray-900">Log out</button>
        </form>
    </section>
</x-layouts.app>
