<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveVisionModelRequest;
use App\Models\VisionModel;
use App\Services\VisionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VisionModelController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', VisionModel::class);

        return view('admin.vision-models.index', ['models' => VisionModel::query()->with('latestRevision')->latest('id')->paginate(20),
            'keyConfigured' => is_string(config('inspections.roboflow.api_key')) && trim(config('inspections.roboflow.api_key')) !== '',
            'automaticEnabled' => config('inspections.roboflow.automatic_decisions') === true]);
    }

    public function create(): View
    {
        Gate::authorize('create', VisionModel::class);

        return view('admin.vision-models.form', ['model' => new VisionModel]);
    }

    public function store(SaveVisionModelRequest $request, VisionCatalog $catalog): RedirectResponse
    {
        $catalog->save($request->user(), $request->validated());

        return redirect()->route('admin.vision-models.index')->with('status', 'Model registered. Select its revision in an inspection template.');
    }

    public function edit(VisionModel $visionModel): View
    {
        Gate::authorize('update', $visionModel);

        return view('admin.vision-models.form', ['model' => $visionModel->load('revisions', 'latestRevision')]);
    }

    public function update(SaveVisionModelRequest $request, VisionModel $visionModel, VisionCatalog $catalog): RedirectResponse
    {
        $catalog->save($request->user(), $request->validated(), $visionModel);

        return redirect()->route('admin.vision-models.index')->with('status', 'Model saved. Templates and assigned checks keep their selected revision.');
    }
}
