<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveVanRequest;
use App\Models\Van;
use App\Services\InspectionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VanController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Van::class);

        return view('admin.vans.index', ['vans' => Van::query()->latest('id')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', Van::class);

        return view('admin.vans.form', ['van' => new Van]);
    }

    public function store(SaveVanRequest $request, InspectionCatalog $catalog): RedirectResponse
    {
        $catalog->saveVan($request->user(), $request->validated());

        return redirect()->route('admin.vans.index')->with('status', 'Van created.');
    }

    public function edit(Van $van): View
    {
        Gate::authorize('update', $van);

        return view('admin.vans.form', compact('van'));
    }

    public function update(SaveVanRequest $request, Van $van, InspectionCatalog $catalog): RedirectResponse
    {
        $catalog->saveVan($request->user(), $request->validated(), $van);

        return redirect()->route('admin.vans.index')->with('status', 'Van updated.');
    }
}
