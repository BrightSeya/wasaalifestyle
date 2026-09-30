<x-layouts.app title="New Activity | WasaaLifestyle" heading="Add Lifestyle Activity">
    <form method="POST" action="{{ route('activities.store') }}" class="max-w-2xl rounded-xl bg-white p-6 shadow-sm">
        @csrf
        @include('activities.partials.form')

        <div class="mt-6 flex gap-3">
            <a href="{{ route('activities.index') }}" class="flex-1 rounded-md border border-gray-300 py-2 text-center text-sm">Cancel</a>
            <button type="submit" class="flex-1 rounded-md bg-brand py-2 text-sm font-semibold hover:bg-brand-dark">Save Activity</button>
        </div>
    </form>
</x-layouts.app>
