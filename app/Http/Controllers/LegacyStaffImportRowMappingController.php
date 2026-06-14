<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportIssueResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyStaffImportRowMappingController extends Controller
{
    public function store(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportIssueResolutionService $resolutionService): RedirectResponse
    {
        abort_unless((int) $row->batch_id === (int) $batch->id, 404);
        $this->authorize('resolveMapping', $row);

        $validated = $request->validate([
            'field' => ['required', 'string', 'in:mda,department,station,cadre,rank,qualification_type'],
            'target_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $resolutionService->applyMapping(
            $row,
            $validated['field'],
            (int) $validated['target_id'],
            $request->user(),
            $validated['notes'] ?? null,
        );

        return redirect()
            ->route('legacy-staff-imports.rows.show', [$batch, $row])
            ->with('success', 'Mapping resolved successfully.');
    }
}
