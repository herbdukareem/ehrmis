<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetGenerationService;
use App\Domain\Budget\Services\BudgetReportService;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class BudgetWorkbookController extends Controller
{
    public function store(Request $request, BudgetGenerationService $service): JsonResponse
    {
        $this->authorize('create', BudgetWorkbook::class);

        $validated = $request->validate([
            'movement_workbook_id' => ['required', 'integer', 'exists:movement_workbooks,id'],
        ]);

        $movementWorkbook = MovementWorkbook::query()
            ->with(['summaries', 'mda'])
            ->findOrFail((int) $validated['movement_workbook_id']);

        abort_unless($request->user()->canAccessMda((int) $movementWorkbook->mda_id), 403);

        $workbook = $service->generateFromMovementWorkbook($movementWorkbook, $request->user()->id);

        return response()->json(['message' => 'Budget workbook generated.', 'data' => ['id' => $workbook->id]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BudgetWorkbook::class);

        $query = BudgetWorkbook::query()->with(['mda', 'approvalWorkflow.steps'])->latest('year');
        $movementOptionsQuery = MovementWorkbook::query()
            ->whereIn('status', ['approved', 'locked'])
            ->with('mda')
            ->latest('year');

        if (! $request->user()->hasGlobalMdaAccess()) {
            $request->user()->scopeToAccessibleMdas($query, 'mda_id');
            $request->user()->scopeToAccessibleMdas($movementOptionsQuery, 'mda_id');
        }

        $budgetIdsByMovement = BudgetWorkbook::query()
            ->pluck('id', 'movement_workbook_id');

        return response()->json([
            'data' => $query->get()->map(fn (BudgetWorkbook $workbook): array => [
                'id' => $workbook->id,
                'mda' => $workbook->mda?->only(['id', 'code', 'name']),
                'year' => $workbook->year,
                'status' => $workbook->status,
                'summary' => $workbook->summary ?? [],
                'movement_workbook_id' => $workbook->movement_workbook_id,
                'approval_status' => $workbook->approvalWorkflow?->status,
            ]),
            'options' => [
                'movement_workbooks' => $movementOptionsQuery->get()->map(fn (MovementWorkbook $workbook): array => [
                    'id' => $workbook->id,
                    'label' => trim(($workbook->mda?->code ?? 'MDA').' '.$workbook->year.' (#'.$workbook->id.')'),
                    'name' => $workbook->name,
                    'mda' => $workbook->mda?->only(['id', 'code', 'name']),
                    'year' => $workbook->year,
                    'status' => $workbook->status,
                    'budget_workbook_id' => $budgetIdsByMovement[(int) $workbook->id] ?? null,
                ])->values(),
            ],
        ]);
    }

    public function show(BudgetWorkbook $budgetWorkbook): JsonResponse
    {
        $this->authorize('view', $budgetWorkbook);

        $budgetWorkbook->load(['mda', 'lines.department', 'lines.salaryScale', 'approvalWorkflow.steps']);

        return response()->json([
            'data' => [
                'id' => $budgetWorkbook->id,
                'mda' => $budgetWorkbook->mda?->only(['id', 'code', 'name']),
                'year' => $budgetWorkbook->year,
                'status' => $budgetWorkbook->status,
                'summary' => $budgetWorkbook->summary ?? [],
                'movement_workbook_id' => $budgetWorkbook->movement_workbook_id,
                'approval_workflow' => $budgetWorkbook->approvalWorkflow,
                'lines' => $budgetWorkbook->lines->map(fn ($line): array => [
                    'department' => $line->department?->name ?? 'Unassigned',
                    'scale' => $line->salaryScale?->code,
                    'level' => $line->level,
                    'staff_count' => $line->staff_count,
                    'retiring_count' => $line->retiring_count,
                    'current_gross_total' => $line->current_gross_total,
                    'proposed_gross_total' => $line->proposed_gross_total,
                    'variance_total' => $line->variance_total,
                ])->values(),
            ],
        ]);
    }

    public function report(BudgetWorkbook $budgetWorkbook, string $report, BudgetReportService $service): Response
    {
        $this->authorize('print', $budgetWorkbook);

        abort_unless(in_array($budgetWorkbook->status, ['approved', 'locked'], true), 403, 'Budget reports are available after approval.');

        try {
            $reportData = $service->build($budgetWorkbook, $report);
        } catch (InvalidArgumentException $exception) {
            abort(404, $exception->getMessage());
        }

        return response()->view('budget.reports.print', [
            'workbook' => $budgetWorkbook->loadMissing(['mda', 'movementWorkbook']),
            'report' => $reportData,
        ]);
    }
}
