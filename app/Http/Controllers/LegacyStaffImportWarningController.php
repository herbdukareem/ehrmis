<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportIssueResolutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyStaffImportWarningController extends Controller
{
    public function ignore(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportIssueResolutionService $resolutionService): RedirectResponse
    {
        abort_unless((int) $row->batch_id === (int) $batch->id, 404);
        $this->authorize('ignoreWarning', $row);

        $validated = $request->validate([
            'warning_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $warning = LegacyStaffImportError::query()
            ->where('row_id', $row->id)
            ->where('batch_id', $batch->id)
            ->where('severity', 'warning')
            ->findOrFail($validated['warning_id']);

        $resolutionService->ignoreWarning($warning, $request->user(), $validated['notes'] ?? null);

        return redirect()
            ->route('legacy-staff-imports.rows.show', [$batch, $row])
            ->with('success', 'Warning marked as reviewed.');
    }
}
