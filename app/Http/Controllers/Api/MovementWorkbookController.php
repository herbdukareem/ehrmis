<?php

namespace App\Http\Controllers\Api;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementSheetGenerationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MovementWorkbookController extends Controller
{
    public function store(Request $request, MovementSheetGenerationService $service): JsonResponse
    {
        $this->authorize('create', MovementWorkbook::class);

        $validated = $request->validate([
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);

        abort_unless(
            $request->user()->hasGlobalMdaAccess() || (int) $request->user()->mda_id === (int) $validated['mda_id'],
            403
        );

        $workbook = $service->generateForMda((int) $validated['mda_id'], (int) $validated['year'], $request->user()->id);

        return response()->json(['message' => 'Movement workbook generated.', 'data' => ['id' => $workbook->id]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MovementWorkbook::class);

        $query = MovementWorkbook::query()->with(['mda', 'approvalWorkflow.steps'])->latest('year');

        if (! $request->user()->hasGlobalMdaAccess()) {
            $query->where('mda_id', $request->user()->mda_id);
        }

        return response()->json([
            'data' => $query->get()->map(fn (MovementWorkbook $workbook): array => [
                'id' => $workbook->id,
                'mda' => $workbook->mda?->only(['id', 'code', 'name']),
                'year' => $workbook->year,
                'status' => $workbook->status,
                'summary' => $workbook->summary ?? [],
                'approval_status' => $workbook->approvalWorkflow?->status,
            ]),
        ]);
    }

    public function show(MovementWorkbook $workbook): JsonResponse
    {
        $this->authorize('view', $workbook);

        $workbook->load(['mda', 'summaries.department', 'summaries.salaryScale', 'approvalWorkflow.steps']);

        return response()->json([
            'data' => [
                'id' => $workbook->id,
                'mda' => $workbook->mda?->only(['id', 'code', 'name']),
                'year' => $workbook->year,
                'status' => $workbook->status,
                'summary' => $workbook->summary ?? [],
                'approval_workflow' => $workbook->approvalWorkflow,
                'summaries' => $workbook->summaries->map(fn ($summary): array => [
                    'department' => $summary->department?->name ?? 'Unassigned',
                    'scale' => $summary->salaryScale?->code,
                    'level' => $summary->level,
                    'staff_count' => $summary->staff_count,
                    'current_gross_total' => $summary->current_gross_total,
                    'proposed_gross_total' => $summary->proposed_gross_total,
                    'variance_total' => $summary->variance_total,
                ])->values(),
            ],
        ]);
    }
}
