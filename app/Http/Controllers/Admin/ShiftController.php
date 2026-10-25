<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreShiftRequest;
use App\Models\InspectionTemplate;
use App\Models\Shift;
use App\Models\User;
use App\Models\Van;
use App\Services\ResourceDeletion;
use App\Services\ShiftAssigner;
use App\Services\ShiftWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function destroy(Request $request, Shift $shift, ResourceDeletion $deletion): RedirectResponse
    {
        Gate::authorize('delete', $shift);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $deletion->delete($request->user(), $shift);

        return redirect()->route('admin.shifts.index')->with('status', 'Shift deleted. Saved inspection history is retained.');
    }

    public function index(Request $request): View
    {
        Gate::authorize('create', Shift::class);

        $data = $request->validate(['status' => ['nullable', Rule::enum(ShiftStatus::class)]]);

        return view('admin.shifts.index', [
            'shifts' => Shift::query()->when($data['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
                ->with('worker')->withCount('checks')->orderByDesc('shift_date')->orderByDesc('id')->paginate(20)->withQueryString(),
            'awaitingCount' => Shift::query()->where('status', ShiftStatus::AwaitingReview)->count(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Shift::class);

        return view('admin.shifts.create', [
            'workers' => User::query()->where('role', Role::User)->where('is_active', true)->orderBy('name')->get(),
            'vans' => Van::query()->where('is_active', true)->orderBy('name')->get(),
            'templates' => InspectionTemplate::query()->where('is_active', true)->whereHas('items', fn (Builder $query): Builder => $query->where('is_required', true))->withCount('items')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreShiftRequest $request, ShiftAssigner $assigner): RedirectResponse
    {
        $shift = $assigner->assign($request->user(), $request->validated());

        return redirect()->route('admin.shifts.show', $shift)->with('status', 'Shift assigned. A separate checklist has been created for this worker.');
    }

    public function show(Shift $shift, ShiftWorkflow $workflow): View
    {
        Gate::authorize('view', $shift);

        $shift->load('worker', 'assignedBy', 'checks');

        return view('admin.shifts.show', [
            'shift' => $shift, 'assessment' => $workflow->assessment($shift),
            'transitions' => $shift->transitions()->with('actor:id,name')->latest('id')->paginate(10, ['*'], 'history_page'),
        ]);
    }
}
