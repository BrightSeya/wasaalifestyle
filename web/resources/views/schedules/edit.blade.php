<x-layouts.app title="Edit Task | WasaaLifestyle" heading="Edit Task">
    <form method="POST" action="{{ route('schedules.update', $schedule) }}" class="max-w-2xl rounded-xl bg-white p-6 shadow-sm">
        @csrf
        @method('PUT')
        @include('schedules.partials.form')

        <div class="mt-6 flex gap-3">
            <a href="{{ route('schedules.index') }}" class="flex-1 rounded-md border border-gray-300 py-2 text-center text-sm">Cancel</a>
            <button type="submit" class="flex-1 rounded-md bg-brand py-2 text-sm font-semibold hover:bg-brand-dark">Save Changes</button>
        </div>
    </form>

    <form method="POST" action="{{ route('schedules.destroy', $schedule) }}" class="mt-4 max-w-2xl"
          onsubmit="return confirm('Delete this entry?')">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-sm font-semibold text-red-600 hover:underline">Delete entry</button>
    </form>
</x-layouts.app>
