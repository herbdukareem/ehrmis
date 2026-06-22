<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetGenerationService;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        if (! $request->user()->hasGlobalMdaAccess()) {
            $request->user()->scopeToAccessibleMdas($query, 'mda_id');
        }

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
}
