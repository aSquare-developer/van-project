@props(['passed', 'total'])
<div {{ $attributes }}>
    @if($total > 0)
        <p class="text-sm font-medium text-slate-700">{{ $passed }} of {{ $total }} required checks passed</p>
        <progress value="{{ $passed }}" max="{{ $total }}" aria-label="Required checks passed" class="mt-3 block h-2 w-full accent-teal-600">{{ $passed }} / {{ $total }}</progress>
    @else
        <p class="text-sm text-slate-500">No required checks in this checklist.</p>
    @endif
</div>
