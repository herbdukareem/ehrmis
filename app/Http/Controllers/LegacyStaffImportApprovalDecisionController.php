<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class LegacyStaffImportApprovalDecisionController extends Controller
{
    public function approve(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportApprovalService $approvalService): RedirectResponse
    {
        $this->authorize('approveApproval', $batch);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $approvalService->approveBatch($batch, $request->user(), $validated['comment'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Batch approval recorded successfully.');
    }

    public function reject(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportApprovalService $approvalService): RedirectResponse
    {
        $this->authorize('rejectApproval', $batch);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $approvalService->rejectBatch($batch, $request->user(), $validated['comment']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Batch rejection recorded successfully.');
    }
}
