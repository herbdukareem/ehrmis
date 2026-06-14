<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyStaffImportPublicationController extends Controller
{
    public function store(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportPublicationService $publicationService): RedirectResponse
    {
        $this->authorize('publish', $batch);

        $summary = $publicationService->publishBatch($batch, $request->user());

        return redirect()
            ->route('legacy-staff-imports.show', $batch)
            ->with('success', 'Batch publication completed.')
            ->with('publicationSummary', $summary);
    }
}
