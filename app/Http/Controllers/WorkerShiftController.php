<?php

namespace App\Http\Controllers;

use App\Enums\CheckStatus;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Services\ShiftWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WorkerShiftController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Shift::class);
        $shifts = Shift::query()->visibleTo($request->user())
            ->where('worker_id', $request->user()->id)
            ->withCount([
                'checks',
                'checks as required_checks_count' => fn (Builder $query) => $query->where('is_required', true),
                'checks as passed_required_checks_count' => fn (Builder $query) => $query->where('is_required', true)->where('status', CheckStatus::Passed),
            ])
            ->orderByDesc('shift_date')->orderByDesc('id')->paginate(20);

        return view('shifts.index', compact('shifts'));
    }

    public function show(Shift $shift, ShiftWorkflow $workflow): View
    {
        Gate::authorize('view', $shift);
        $shift->load('checks', 'worker:id,name');
        $required = $shift->checks->where('is_required', true);

        return view('shifts.show', [
            'shift' => $shift,
            'requiredCount' => $required->count(),
            'passedCount' => $required->where('status', CheckStatus::Passed)->count(),
            'assessment' => $workflow->assessment($shift),
            'transitions' => $shift->transitions()->with('actor:id,name')->latest('id')->paginate(10, ['*'], 'history_page'),
        ]);
    }

    public function check(Shift $shift, ShiftCheck $check): View
    {
        Gate::authorize('view', $shift);
        Gate::authorize('view', $check);

        $check->load('referencePhoto');
        $attempts = $check->attempts()->with('photo')->paginate(10);

        return view('shifts.check', compact('shift', 'check', 'attempts'));
    }
}
