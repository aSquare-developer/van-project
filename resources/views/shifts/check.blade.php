<x-layout title="Inspection item">
    <a href="{{ route('shifts.show', $shift) }}" class="inline-block py-2 text-sm underline">← Back to checklist</a>
    <p class="mb-3 mt-5 break-words text-sm text-slate-500">{{ $shift->title }} · {{ $shift->shift_date->format('d M Y') }}</p>
    <h1 class="break-words text-3xl font-semibold tracking-tight">{{ $check->position }}. {{ $check->title }}</h1>
    <div class="mb-8 mt-4 flex flex-wrap items-center gap-3"><x-status-badge :status="$check->status" /><span class="text-sm font-medium text-slate-500">{{ $check->is_required ? 'Required check' : 'Optional check' }}</span></div>
    <section class="mb-5 max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
        <h2 class="text-lg font-semibold">Instructions</h2>
        <p class="mt-4 whitespace-pre-line break-words leading-relaxed text-slate-700">{{ $check->instructions }}</p>
        @if($check->referencePhoto)
            <figure class="mt-5"><img src="{{ route('shifts.checks.reference', [$shift, $check]) }}" alt="Reference example for {{ $check->title }}" loading="lazy" class="max-h-96 w-full rounded-xl object-contain"><figcaption class="mt-2 text-sm text-slate-500">Reference example</figcaption></figure>
        @endif
        <p class="mt-6 break-words text-sm text-slate-500">Van: {{ $shift->van_name }}{{ $shift->van_registration ? ' — '.$shift->van_registration : '' }}</p>
    </section>
    <section class="max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 sm:p-7" aria-label="Inspection result">
        <h2 class="text-lg font-semibold">Result</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $check->status->description() }}</p>
    </section>
    @can('submitPhoto', $check)
        <section class="mt-5 max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 sm:p-7" aria-label="Photo capture">
            <h2 class="text-lg font-semibold">Photograph this item</h2>
            <p class="mt-3 text-sm text-slate-600">Keep the complete area visible. Review the photo before sending it.</p>
            <form data-photo-upload method="POST" enctype="multipart/form-data" action="{{ route('shifts.checks.photos.store', [$shift, $check]) }}" class="mt-5 space-y-4">
                @csrf
                @php($submissionKey = old('submission_key'))
                <input type="hidden" name="submission_key" value="{{ is_string($submissionKey) && Illuminate\Support\Str::isUuid($submissionKey) ? $submissionKey : Illuminate\Support\Str::uuid() }}">
                <div class="flex flex-wrap gap-3"><button type="button" data-photo-camera hidden class="rounded-lg border border-slate-300 px-4 py-3 text-sm font-medium">Take photo</button><button type="button" data-photo-picker hidden class="rounded-lg border border-slate-300 px-4 py-3 text-sm font-medium">Choose photo</button></div>
                <label class="block text-sm font-medium">Photo<input data-photo-file type="file" name="photo" accept="image/jpeg,image/png,image/webp" required class="mt-2 block w-full rounded border border-slate-300 p-3 text-sm"></label>
                <p class="text-xs text-slate-500">JPEG, PNG or WebP, up to 10 MB and 32 megapixels.</p>
                <p data-photo-error role="alert" hidden class="text-sm text-red-700"></p>
                <img data-photo-preview hidden alt="Selected photo preview" class="max-h-96 w-full rounded-xl border border-slate-200 object-contain">
                <button type="button" data-photo-clear hidden class="rounded-lg border border-slate-300 px-4 py-2 text-sm">Clear photo</button>
                <p class="text-sm text-slate-500">Uploading saves a new attempt. It does not mark this item as passed.</p>
                <button data-photo-submit class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white disabled:cursor-default disabled:opacity-40">Upload photo</button>
            </form>
        </section>
    @endcan
    <section class="mt-8 max-w-3xl" aria-label="Photo attempts">
        <h2 class="mb-4 text-xl font-semibold">Photo attempts</h2>
        <div class="space-y-4">
            @forelse($attempts as $attempt)
                <article class="rounded-xl border border-slate-200 bg-white p-5">
                    <h3 class="text-sm font-semibold">Photo received · Attempt #{{ $attempt->id }}</h3>
                    <time class="mt-2 block text-xs text-slate-500" datetime="{{ $attempt->created_at->toIso8601String() }}">{{ $attempt->created_at->timezone(config('app.timezone'))->format('d M Y H:i:s') }}</time>
                    @if($attempt->result)
                        @php($attemptResult = App\Enums\CheckStatus::from($attempt->result))
                        <div class="mt-3"><x-status-badge :status="$attemptResult" /></div>
                        <p class="mt-3 text-sm text-slate-600">{{ $attemptResult->description() }}</p>
                        @if($attempt->status === 'error')
                            <p class="mt-2 text-sm text-slate-600">Analysis is unavailable. Contact your administrator or submit a new photo when the service is available.</p>
                        @else
                            @if(($attempt->analysis_config['provider'] ?? null) === 'roboflow')
                                @if($attempt->decision_mode === 'advisory')<p class="mt-2 text-sm text-slate-600">Advisory analysis only. Administrator review is required; this observation does not approve or reject the check.</p>@endif
                                <p class="mt-2 text-sm text-slate-600">Predicted class: {{ $attempt->detected_class }}</p>
                                <p class="mt-2 break-all text-xs text-slate-500">Model: {{ $attempt->model_version }} · Model score: {{ number_format($attempt->confidence * 100, 1) }}% (not measured accuracy)</p>
                            @else
                                <p class="mt-2 text-xs text-slate-500">Model: {{ $attempt->model_version }} · Confidence: {{ number_format($attempt->confidence * 100, 1) }}%</p>
                            @endif
                        @endif
                    @else
                        <p class="mt-3 text-sm text-slate-600">{{ $attempt->status === 'processing' ? 'Analysis in progress.' : 'Awaiting analysis.' }} No recognition result has been recorded for this photo. Refresh this page to check for a result.</p>
                    @endif
                    @if($attempt->photo->purged_at)
                        <p class="mt-3 text-sm text-slate-500">Photo removed under the retention policy. The attempt record is preserved.</p>
                    @else
                        <a class="mt-4 block" href="{{ route('shifts.checks.attempts.photo', [$shift, $check, $attempt]) }}" target="_blank" rel="noopener"><img src="{{ route('shifts.checks.attempts.photo', [$shift, $check, $attempt]) }}" alt="Photo submitted in attempt {{ $attempt->id }}" loading="lazy" class="max-h-72 w-full rounded-lg object-contain"></a>
                    @endif
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-300 p-6 text-sm text-slate-500">No photos submitted yet.</p>
            @endforelse
        </div>
        <div class="mt-5">{{ $attempts->links() }}</div>
    </section>
</x-layout>
