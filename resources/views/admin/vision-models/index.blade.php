<x-layout title="Vision models">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-semibold">Vision models</h1><p class="mt-2 text-slate-500">Register Roboflow classification workflows and their inspection rules.</p></div><a href="{{ route('admin.vision-models.create') }}" class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Add model</a></div>
    <p class="mb-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">Server API key: <strong>{{ $keyConfigured ? 'configured' : 'not configured — contact the server administrator' }}</strong>. Automatic decisions: <strong>{{ $automaticEnabled ? 'enabled for opted-in revisions' : 'disabled — advisory results only' }}</strong>.</p>
    <p class="mb-6 text-sm text-slate-500">Training takes place in Roboflow. Activate a model, then choose a revision in an inspection template. Existing assignments retain their settings.</p>
    <div class="space-y-4">
        @forelse($models as $model)
            <article class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ $model->name }}</h2><p class="mt-1 text-sm capitalize text-slate-500">{{ $model->status }} · revision {{ $model->latestRevision?->version }}</p></div><a class="rounded border border-slate-300 px-4 py-2 text-sm" href="{{ route('admin.vision-models.edit', $model) }}">Edit model</a></div>
                <div class="mt-4"><x-delete-resource :action="route('admin.vision-models.destroy', $model)" label="model" :name="$model->name" message="Removes this model from this application's catalog. Existing assignments retain their settings. The model in Roboflow is unaffected." /></div>
                @if($model->latestRevision)
                    @php($settings = $model->latestRevision->settings)
                    <p class="mt-4 break-all text-sm text-slate-600">{{ $settings['model_id'] }}</p>
                    <p class="mt-2 text-sm text-slate-600">Pass: {{ $settings['passing_class'] }} · Fail: {{ $settings['failing_class'] }} · Minimum score: {{ number_format($settings['confidence_threshold'] * 100, 1) }}%</p>
                    <p class="mt-2 text-xs text-slate-500">{{ $settings['automatic_decisions'] ? 'Automatic decisions requested; server enablement also required.' : 'Advisory revision: observations require administrator review.' }}</p>
                @endif
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 p-6 text-slate-500">No models registered. Add your trained model and Workflow to begin.</p>
        @endforelse
    </div>
    <div class="mt-5">{{ $models->links() }}</div>
</x-layout>
