<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreShiftRequest;
use App\Models\InspectionTemplate;
use App\Models\Shift;
use App\Models\User;
use App\Models\Van;
use App\Services\ShiftAssigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function index(): View
    {
        Gate::authorize('create', Shift::class);

        return view('admin.shifts.index', ['shifts' => Shift::query()->with('worker')->withCount('checks')->orderByDesc('shift_date')->orderByDesc('id')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', Shift::class);

        return view('admin.shifts.create', [
            'workers' => User::query()->where('role', Role::User)->where('is_active', true)->orderBy('name')->get(),
            'vans' => Van::query()->where('is_active', true)->orderBy('name')->get(),
            'templates' => InspectionTemplate::query()->where('is_active', true)->has('items')->withCount('items')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreShiftRequest $request, ShiftAssigner $assigner): RedirectResponse
    {
        $shift = $assigner->assign($request->user(), $request->validated());

        return redirect()->route('admin.shifts.show', $shift)->with('status', 'Shift assigned. A separate checklist has been created for this worker.');
    }

    public function show(Shift $shift): View
    {
        Gate::authorize('view', $shift);

        return view('admin.shifts.show', ['shift' => $shift->load('worker', 'assignedBy', 'checks')]);
    }
}
