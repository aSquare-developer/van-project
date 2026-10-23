<x-layout title="Assign shift">
    <h1 class="mb-2 text-3xl font-semibold">Assign shift</h1>
    <p class="mb-8 text-slate-500">Assign one worker per shift record. For multiple workers, create a separate assignment for each.</p>
    @if($workers->isEmpty() || $vans->isEmpty() || $templates->isEmpty())
        <div class="mb-6 space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
            <p class="font-semibold">Complete the setup before assigning a shift:</p>
            @if($workers->isEmpty())<p><a class="underline" href="{{ route('admin.users.create') }}">Create an active user with the User role.</a></p>@endif
            @if($vans->isEmpty())<p><a class="underline" href="{{ route('admin.vans.create') }}">Add an active van.</a></p>@endif
            @if($templates->isEmpty())<p><a class="underline" href="{{ route('admin.templates.create') }}">Create an active template with checklist items.</a></p>@endif
        </div>
    @endif
    <form method="POST" action="{{ route('admin.shifts.store') }}" class="max-w-xl space-y-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
        @csrf
        <label class="block">Shift title<input name="title" value="{{ old('title') }}" maxlength="255" required placeholder="Friday shift" class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
        <label class="block">Date<input type="date" name="shift_date" value="{{ old('shift_date', today()->format('Y-m-d')) }}" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
        <label class="block">Worker<select name="worker_id" required class="mt-2 block w-full rounded border border-slate-300 p-3"><option value="">Select worker</option>@foreach($workers as $worker)<option value="{{ $worker->id }}" @selected((string) old('worker_id') === (string) $worker->id)>{{ $worker->name }} — {{ $worker->email }}</option>@endforeach</select></label>
        <label class="block">Van<select name="van_id" required class="mt-2 block w-full rounded border border-slate-300 p-3"><option value="">Select van</option>@foreach($vans as $van)<option value="{{ $van->id }}" @selected((string) old('van_id') === (string) $van->id)>{{ $van->name }}{{ $van->registration ? ' — '.$van->registration : '' }}</option>@endforeach</select></label>
        <label class="block">Inspection template<select name="inspection_template_id" required class="mt-2 block w-full rounded border border-slate-300 p-3"><option value="">Select template</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected((string) old('inspection_template_id') === (string) $template->id)>{{ $template->name }} — {{ $template->items_count }} items</option>@endforeach</select></label>
        <p class="text-sm text-slate-500">The checklist is copied when you assign the shift. Later template changes do not affect this assignment.</p>
        <div class="flex flex-wrap gap-3"><button @disabled($workers->isEmpty() || $vans->isEmpty() || $templates->isEmpty()) class="rounded-lg bg-slate-900 px-5 py-3 text-white disabled:cursor-default disabled:opacity-40">Assign shift</button><a href="{{ route('admin.shifts.index') }}" class="p-3 underline">Cancel</a></div>
    </form>
</x-layout>
