<x-layout :title="$model->exists ? 'Edit vision model' : 'Add vision model'">
    <h1 class="mb-3 text-3xl font-semibold">{{ $model->exists ? 'Edit vision model' : 'Add vision model' }}</h1>
    <p class="mb-6 max-w-3xl text-slate-500">Register a trained Roboflow classification model and its saved Workflow. New objects using this format can be added here without changing the application.</p>
    @php($settings = $model->latestRevision?->settings ?? [])
    <form method="POST" action="{{ $model->exists ? route('admin.vision-models.update', $model) : route('admin.vision-models.store') }}" class="max-w-3xl space-y-6">
        @csrf
        @if($model->exists) @method('PUT') @endif
        <input type="hidden" name="base_revision" value="{{ old('base_revision', $model->latestRevision?->id) }}">
        <div class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
            <label class="block">Name<input name="name" value="{{ old('name', $model->name) }}" maxlength="255" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            <label class="block">Status<select name="status" required class="mt-2 block w-full rounded border border-slate-300 p-3">@foreach(['draft' => 'Draft', 'active' => 'Active', 'disabled' => 'Disabled'] as $key => $label)<option value="{{ $key }}" @selected(old('status', $model->status ?? 'draft') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="block">Analysis type<select name="analysis_type" class="mt-2 block w-full rounded border border-slate-300 p-3"><option value="classification">Image classification</option></select></label>
            <label class="block">Exact Model ID<input name="model_id" value="{{ old('model_id', $settings['model_id'] ?? 'lisettes-workspace/silicone-shelf-status-2-resnet18-t1') }}" maxlength="255" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            <label class="block">Workspace<input name="workspace" value="{{ old('workspace', $settings['workspace'] ?? 'lisettes-workspace') }}" maxlength="200" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            <label class="block">Workflow ID<input name="workflow_id" value="{{ old('workflow_id', $settings['workflow_id'] ?? '') }}" maxlength="200" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            <p class="text-sm text-slate-500">Copy the Model ID and Workflow ID from Roboflow. The Workflow must return one classification prediction with its resolved model ID. API keys are configured privately on the server.</p>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block">Image input name<input name="input_name" value="{{ old('input_name', $settings['input_name'] ?? 'image') }}" maxlength="80" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
                <label class="block">Prediction output name<input name="output_name" value="{{ old('output_name', $settings['output_name'] ?? 'predictions') }}" maxlength="80" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            </div>
        </div>
        <div class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
            <h2 class="text-xl font-semibold">Inspection rule</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block">Passing class<input name="passing_class" value="{{ old('passing_class', $settings['passing_class'] ?? 'occupied') }}" maxlength="100" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
                <label class="block">Failing class<input name="failing_class" value="{{ old('failing_class', $settings['failing_class'] ?? 'empty') }}" maxlength="100" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            </div>
            @php($uncertain = old('uncertain_classes', $settings['uncertain_classes'] ?? []))
            <label class="block">Other trained classes that require review (optional, comma separated)<input name="uncertain_classes" value="{{ is_array($uncertain) ? implode(', ', $uncertain) : $uncertain }}" class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            <label class="block">Minimum model score (0.01–1)<input type="number" name="confidence_threshold" min="0.01" max="1" step="0.01" value="{{ old('confidence_threshold', $settings['confidence_threshold'] ?? 0.8) }}" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            <input type="hidden" name="automatic_decisions" value="0">
            <label class="flex items-start gap-3"><input type="checkbox" name="automatic_decisions" value="1" @checked(old('automatic_decisions', $settings['automatic_decisions'] ?? false)) class="mt-1"><span>Allow automatic pass/fail for this revision after validation</span></label>
            <p class="text-sm text-slate-500">Leave automatic decisions off for the pilot. Test occupied, empty, wrong-area, obscured and blurred photos before enabling them. A high model score does not prove that the right area was photographed. Server enablement is also required.</p>
        </div>
        <p class="text-sm text-slate-500">Changes to the model or rule create a retained revision. Templates keep their selected revision: choose the new revision in the template when ready. Disabled models cannot be used for new assignments. Existing assignments remain unchanged.</p>
        <div class="flex flex-wrap gap-3"><button class="rounded-lg bg-slate-900 px-5 py-3 text-white">Save model</button><a href="{{ route('admin.vision-models.index') }}" class="p-3 underline">Cancel</a></div>
    </form>
    @if($model->exists)
        <section class="mt-10 max-w-3xl"><h2 class="mb-4 text-xl font-semibold">Revision history</h2><div class="space-y-3">@foreach($model->revisions as $revision)<article class="rounded-xl border border-slate-200 bg-white p-4"><p class="font-medium">Revision {{ $revision->version }} · {{ $revision->created_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</p><p class="mt-2 break-all text-sm text-slate-600">{{ $revision->settings['model_id'] }}</p><p class="mt-2 text-sm text-slate-500">Pass: {{ $revision->settings['passing_class'] }} · Fail: {{ $revision->settings['failing_class'] }} · {{ $revision->settings['automatic_decisions'] ? 'Automatic decisions requested' : 'Advisory' }}</p></article>@endforeach</div></section>
    @endif
</x-layout>
