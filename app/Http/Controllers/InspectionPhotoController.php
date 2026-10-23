<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInspectionPhotoRequest;
use App\Models\InspectionAttempt;
use App\Models\InspectionPhoto;
use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Services\InspectionPhotoSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InspectionPhotoController extends Controller
{
    public function store(StoreInspectionPhotoRequest $request, Shift $shift, ShiftCheck $check, InspectionPhotoSubmission $submission): RedirectResponse
    {
        $submission->submit($request->user(), $check, $request->file('photo'), $request->validated('submission_key'));

        return redirect()->route('shifts.checks.show', [$shift, $check])->with('status', 'Photo received. It has not been analysed yet.');
    }

    public function attempt(Shift $shift, ShiftCheck $check, InspectionAttempt $attempt): StreamedResponse
    {
        Gate::authorize('view', $check);

        return $this->image($attempt->photo);
    }

    public function reference(Shift $shift, ShiftCheck $check): StreamedResponse
    {
        Gate::authorize('view', $check);
        abort_unless($check->referencePhoto, 404);

        return $this->image($check->referencePhoto);
    }

    public function templateReference(InspectionTemplate $template, InspectionTemplateItem $item): StreamedResponse
    {
        Gate::authorize('view', $template);
        abort_unless($item->referencePhoto, 404);

        return $this->image($item->referencePhoto);
    }

    private function image(InspectionPhoto $photo): StreamedResponse
    {
        abort_if($photo->purged_at || ! Storage::disk('inspection-photos')->exists($photo->path), 404);

        return Storage::disk('inspection-photos')->response($photo->path, 'inspection-photo', [
            'Content-Type' => $photo->mime,
            'Cache-Control' => 'no-store, private',
        ], 'inline');
    }
}
