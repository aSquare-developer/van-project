<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveInspectionTemplateRequest;
use App\Models\InspectionTemplate;
use App\Services\InspectionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InspectionTemplateController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', InspectionTemplate::class);

        return view('admin.templates.index', ['templates' => InspectionTemplate::query()->withCount('items')->latest('id')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', InspectionTemplate::class);

        return view('admin.templates.form', ['template' => new InspectionTemplate, 'checks' => config('inspections.checks')]);
    }

    public function store(SaveInspectionTemplateRequest $request, InspectionCatalog $catalog): RedirectResponse
    {
        $catalog->saveTemplate($request->user(), $request->validated());

        return redirect()->route('admin.templates.index')->with('status', 'Inspection template created.');
    }

    public function edit(InspectionTemplate $template): View
    {
        Gate::authorize('update', $template);

        return view('admin.templates.form', ['template' => $template->load('items'), 'checks' => config('inspections.checks')]);
    }

    public function update(SaveInspectionTemplateRequest $request, InspectionTemplate $template, InspectionCatalog $catalog): RedirectResponse
    {
        $catalog->saveTemplate($request->user(), $request->validated(), $template);

        return redirect()->route('admin.templates.index')->with('status', 'Template updated. Existing shift checklists are unchanged.');
    }
}
