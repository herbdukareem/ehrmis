<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetWorkbookWorkflowService;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportApprovalService;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementWorkbookWorkflowService;
use App\Http\Controllers\Controller;
use App\Jobs\PublishLegacyStaffImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WorkflowActionController extends Controller
{
    public function importSubmit(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportApprovalService $service): JsonResponse
    {
        $this->authorize('submitApproval', $batch);

        return $this->run(fn () => $service->submitBatch($batch, $request->user()), 'Import batch submitted for approval.');
    }

    public function importApprove(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportApprovalService $service): JsonResponse
    {
        $this->authorize('approveApproval', $batch);

        return $this->run(fn () => $service->approveBatch($batch, $request->user(), $request->string('comment')->toString() ?: null), 'Import batch approved.');
    }

    public function importReject(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportApprovalService $service): JsonResponse
    {
        $this->authorize('rejectApproval', $batch);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $service->rejectBatch($batch, $request->user(), $validated['comment']), 'Import batch rejected.');
    }

    public function importPublish(Request $request, LegacyStaffImportBatch $batch): JsonResponse
    {
        $this->authorize('publish', $batch);

        DB::transaction(function () use ($batch, $request): void {
            $lockedBatch = LegacyStaffImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($lockedBatch->status === 'publishing') {
                throw new InvalidArgumentException('This import batch is already being published.');
            }

            $summary = $lockedBatch->summary ?? [];
            unset($summary['publication_failure']);

            $lockedBatch->forceFill([
                'status' => 'publishing',
                'summary' => $summary,
            ])->save();

            PublishLegacyStaffImportBatch::dispatch($lockedBatch->id, $request->user()->id)->afterCommit();
        });

        return response()->json([
            'message' => 'Import batch publishing has started. You can leave this page while it runs.',
        ], 202);
    }

    public function movementReview(Request $request, MovementWorkbook $workbook, MovementWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('review', $workbook);

        return $this->run(fn () => $service->markReviewed($workbook, $request->user()), 'Movement workbook submitted for approval.');
    }

    public function movementApprove(Request $request, MovementWorkbook $workbook, MovementWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $workbook);

        return $this->run(fn () => $service->approve($workbook, $request->user()), 'Movement workbook approved.');
    }

    public function movementReject(Request $request, MovementWorkbook $workbook, MovementWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $workbook);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $service->reject($workbook, $request->user(), $validated['comment']), 'Movement workbook rejected.');
    }

    public function movementLock(MovementWorkbook $workbook, MovementWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $workbook);

        return $this->run(fn () => $service->lock($workbook), 'Movement workbook locked.');
    }

    public function movementReopen(MovementWorkbook $workbook, MovementWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $workbook);

        return $this->run(fn () => $service->reopen($workbook), 'Movement workbook reopened.');
    }

    public function budgetSubmit(Request $request, BudgetWorkbook $budgetWorkbook, BudgetWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        return $this->run(fn () => $service->submit($budgetWorkbook, $request->user()), 'Budget workbook submitted for approval.');
    }

    public function budgetApprove(Request $request, BudgetWorkbook $budgetWorkbook, BudgetWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        return $this->run(fn () => $service->approve($budgetWorkbook, $request->user()), 'Budget workbook approved.');
    }

    public function budgetReject(Request $request, BudgetWorkbook $budgetWorkbook, BudgetWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $budgetWorkbook);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $service->reject($budgetWorkbook, $request->user(), $validated['comment']), 'Budget workbook rejected.');
    }

    public function budgetLock(BudgetWorkbook $budgetWorkbook, BudgetWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        return $this->run(fn () => $service->lock($budgetWorkbook), 'Budget workbook locked.');
    }

    public function budgetReopen(BudgetWorkbook $budgetWorkbook, BudgetWorkbookWorkflowService $service): JsonResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        return $this->run(fn () => $service->reopen($budgetWorkbook), 'Budget workbook reopened.');
    }

    protected function run(callable $action, string $message): JsonResponse
    {
        try {
            $result = $action();

            return response()->json(['message' => $message, 'data' => $result]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
