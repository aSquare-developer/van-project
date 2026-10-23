@props(['shift', 'transitions'])
<section class="mt-8" aria-label="Inspection decision history">
    <h2 class="mb-4 text-xl font-semibold">Decision history</h2>
    <div class="space-y-4">
        @forelse($transitions as $transition)
            <article class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3"><h3 class="font-semibold">{{ $transition->decision->label() }}</h3><x-status-badge :status="$transition->to_status" /></div>
                <p class="mt-2 break-words text-xs text-slate-500">{{ $transition->actor->name }} · <time datetime="{{ $transition->created_at->toIso8601String() }}">{{ $transition->created_at->timezone(config('app.timezone'))->format('d M Y H:i:s') }}</time></p>
                @if($transition->comment)<p class="mt-4 whitespace-pre-line break-words text-sm text-slate-700">{{ $transition->comment }}</p>@endif
                <details class="mt-4 text-sm"><summary class="cursor-pointer">Photo attempts at this decision</summary><ul class="mt-3 space-y-2">
                    @foreach($transition->snapshot as $item)
                        <li>Item {{ $item['position'] }} · {{ App\Enums\CheckStatus::from($item['result'])->label() }}
                            @if($item['attempt_id'])<a class="ml-2 underline" href="{{ route('shifts.checks.attempts.photo', [$shift, $item['check_id'], $item['attempt_id']]) }}" target="_blank" rel="noopener">Photo attempt #{{ $item['attempt_id'] }}</a>@else<span class="ml-2 text-slate-500">No photo (optional)</span>@endif
                        </li>
                    @endforeach
                </ul><p class="mt-3 text-xs text-slate-500">Photos may later be removed under the retention policy; attempt and decision records remain.</p></details>
            </article>
        @empty
            <p class="text-sm text-slate-500">No decisions recorded yet.</p>
        @endforelse
    </div>
    <div class="mt-5">{{ $transitions->links() }}</div>
</section>
