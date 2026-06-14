<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LegacyStaffImportApprovalSubmissionController extends Controller
{
    public function store(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportApprovalService $approvalService): RedirectResponse
    {
        $this->authorize('submitApproval', $batch);

        try {
            $approvalService->submitBatch($batch, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Batch submitted for approval.');
    }
}
