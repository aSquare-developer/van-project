@props(['action', 'label', 'name', 'message' => 'Saved inspection history is retained.'])
<details class="text-sm">
    <summary class="cursor-pointer rounded-lg border border-red-200 px-4 py-2 text-red-700">Delete {{ $label }}</summary>
    <form method="POST" action="{{ $action }}" class="mt-3 max-w-sm space-y-3 rounded-lg border border-red-200 bg-red-50 p-4">
        @csrf
        @method('DELETE')
        <p class="break-words font-medium">Delete “{{ $name }}”?</p>
        <p class="text-slate-600">{{ $message }}</p>
        <label class="flex items-start gap-2"><input type="checkbox" name="confirmed" value="1" required class="mt-1">I confirm this deletion.</label>
        <button class="rounded-lg bg-red-700 px-4 py-2 font-medium text-white">Delete {{ $label }}</button>
    </form>
</details>
