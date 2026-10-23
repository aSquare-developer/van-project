<fieldset data-checklist-row class="min-w-0 space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
    <legend class="px-2 text-sm font-semibold">Item <span data-item-number>{{ is_numeric($index) ? $index + 1 : '' }}</span></legend>
    <label class="block text-sm font-medium">Title<input data-field="title" name="items[{{ $index }}][title]" value="{{ $item['title'] ?? '' }}" maxlength="255" required class="mt-2 block w-full rounded border border-slate-300 bg-white p-3 font-normal"></label>
    <label class="block text-sm font-medium">Instructions<textarea data-field="instructions" name="items[{{ $index }}][instructions]" rows="3" maxlength="3000" required class="mt-2 block w-full rounded border border-slate-300 bg-white p-3 font-normal">{{ $item['instructions'] ?? '' }}</textarea></label>
    <label class="block text-sm font-medium">Check<select data-field="check_key" name="items[{{ $index }}][check_key]" required class="mt-2 block w-full rounded border border-slate-300 bg-white p-3 font-normal">
        @foreach($checks as $key => $check)<option value="{{ $key }}" @selected(($item['check_key'] ?? array_key_first($checks)) === $key)>{{ $check['name'] }}</option>@endforeach
    </select></label>
    <input type="hidden" data-field="is_required" name="items[{{ $index }}][is_required]" value="0">
    <label class="flex items-center gap-3 text-sm"><input type="checkbox" data-field="is_required" name="items[{{ $index }}][is_required]" value="1" @checked($item['is_required'] ?? true)> Required to complete the shift</label>
    <div class="flex flex-wrap gap-2">
        <button type="button" data-item-action="up" aria-label="Move item up" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm disabled:cursor-default disabled:opacity-40">Move up</button>
        <button type="button" data-item-action="down" aria-label="Move item down" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm disabled:cursor-default disabled:opacity-40">Move down</button>
        <button type="button" data-item-action="remove" class="rounded border border-red-200 bg-white px-3 py-2 text-sm text-red-700 disabled:cursor-default disabled:opacity-40">Remove item</button>
    </div>
</fieldset>
