<?php

namespace App\Http\Controllers\Api;

use App\Domain\Workplan\Models\WorkplanEvidence;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use App\Domain\Workplan\Services\WorkplanEvidenceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workplan\StoreWorkplanEvidenceRequest;
use App\Http\Resources\WorkplanEvidenceResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WorkplanEvidenceController extends Controller
{
    public function store(StoreWorkplanEvidenceRequest $request, WorkplanProgressReport $report, WorkplanEvidenceService $service)
    {
        return (new WorkplanEvidenceResource($service->store($report, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function download(Request $request, int $evidence)
    {
        $record = $this->evidenceFor($request, $evidence);
        abort_unless($record->file_path && Storage::disk('local')->exists($record->file_path), 404);

        return response()->file(Storage::disk('local')->path($record->file_path), [
            'Content-Disposition' => 'attachment; filename="'.basename($record->file_path).'"',
        ]);
    }

    public function destroy(Request $request, int $evidence, WorkplanEvidenceService $service)
    {
        $service->delete($this->evidenceFor($request, $evidence), $request->user());
        return response()->noContent();
    }

    private function evidenceFor(Request $request, int $id): WorkplanEvidence
    {
        $evidence = WorkplanEvidence::withoutGlobalScopes()->findOrFail($id);
        $report = WorkplanProgressReport::withoutGlobalScopes()->findOrFail($evidence->workplan_progress_report_id);
        abort_unless((int) $evidence->mda_id === (int) $report->mda_id, 404);
        abort_unless($request->user()->can('view-workplans') && $request->user()->canAccessMda($evidence->mda_id), 403);
        abort_unless($report->activity->department_id === null || $request->user()->canAccessDepartment($report->activity->department_id), 403);

        return $evidence;
    }
}
