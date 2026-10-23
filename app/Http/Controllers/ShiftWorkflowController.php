<?php

namespace App\Http\Controllers;

use App\Enums\ShiftDecision;
use App\Http\Requests\ShiftActionRequest;
use App\Models\Shift;
use App\Services\ShiftWorkflow;
use Illuminate\Http\RedirectResponse;

class ShiftWorkflowController extends Controller
{
    public function complete(ShiftActionRequest $request, Shift $shift, ShiftWorkflow $workflow): RedirectResponse
    {
        return $this->apply($request, $shift, $workflow, ShiftDecision::Complete);
    }

    public function requestReview(ShiftActionRequest $request, Shift $shift, ShiftWorkflow $workflow): RedirectResponse
    {
        return $this->apply($request, $shift, $workflow, ShiftDecision::RequestReview);
    }

    public function review(ShiftActionRequest $request, Shift $shift, ShiftWorkflow $workflow): RedirectResponse
    {
        return $this->apply($request, $shift, $workflow, ShiftDecision::from($request->validated('decision')));
    }

    private function apply(ShiftActionRequest $request, Shift $shift, ShiftWorkflow $workflow, ShiftDecision $decision): RedirectResponse
    {
        $workflow->act($request->user(), $shift, $decision, $request->validated());

        return redirect()->route($decision->isWorkerAction() ? 'shifts.show' : 'admin.shifts.show', $shift)
            ->with('status', 'Action recorded. The current inspection status is shown below.');
    }
}
