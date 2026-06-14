<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportPublicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyStaffImportRowPublicationController extends Controller
{
    public function store(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportPublicationService $publicationService): RedirectResponse
    {
        abort_unless((int) $row->batch_id === (int) $batch->id, 404);
        $this->authorize('publish', $row);

        $result = $publicationService->publishRow($row, $request->user());

        return redirect()
            ->route('legacy-staff-imports.rows.show', [$batch, $row])
            ->with('success', match ($result['status']) {
                'published' => 'Import row published successfully.',
                'skipped_already_published' => 'This import row was already published.',
                default => 'Import row could not be published because blocking issues remain.',
            });
    }
}
