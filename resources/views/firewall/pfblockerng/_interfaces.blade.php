{{-- Checkbox list of firewall interfaces. Needs: $name, $selected (array), $interfaces (key => label) --}}
<div class="mt-1 grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1.5 rounded-md border border-gray-200 dark:border-gray-700 p-3">
    @forelse ($interfaces as $key => $label)
        <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
            <input type="checkbox" name="{{ $name }}[]" value="{{ $key }}" class="{{ $ui['check'] }}" @checked(in_array($key, (array) $selected, true))>
            {{ $label }}
        </label>
    @empty
        <p class="text-sm text-gray-500">No interfaces found.</p>
    @endforelse
</div>
