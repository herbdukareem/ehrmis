<?php

namespace App\Http\Controllers\Api;

use App\Domain\Movement\Exports\MovementSummaryExport;
use App\Domain\Movement\Exports\MovementDetailExport;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementDepartmentSummaryService;
use App\Domain\Movement\Services\MovementSheetGenerationService;
use App\Domain\Organization\Models\Mda;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateMovementWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MovementWorkbookController extends Controller
{
    public function store(Request $request, MovementSheetGenerationService $service): JsonResponse
    {
        $this->authorize('create', MovementWorkbook::class);

        $validated = $request->validate([
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'name' => ['required', 'string', 'max:150'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'budget_year' => ['required', 'integer', 'min:2020', 'max:2100', 'gte:year'],
            'budget_minimum_step' => ['required', 'integer', 'min:1', 'max:15'],
        ]);

        abort_unless($request->user()->canAccessMda((int) $validated['mda_id']), 403);

        $workbook = $service->initializeWorkbook(
            (int) $validated['mda_id'],
            (int) $validated['year'],
            $request->user()->id,
            $validated['name'],
            (int) $validated['budget_year'],
            (int) $validated['budget_minimum_step'],
        );

        GenerateMovementWorkbook::dispatch(
            $workbook->id,
            (int) $validated['year'],
            (int) $validated['budget_year'],
            (int) $validated['budget_minimum_step'],
        )->afterCommit();

        return response()->json([
            'message' => 'Movement workbook generation has started. You can leave this page while it runs.',
            'data' => ['id' => $workbook->id],
        ], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MovementWorkbook::class);

        $query = MovementWorkbook::query()->with(['mda', 'approvalWorkflow.steps'])->latest('year');

        if (! $request->user()->hasGlobalMdaAccess()) {
            $request->user()->scopeToAccessibleMdas($query, 'mda_id');
        }

        return response()->json([
            'data' => $query->get()->map(fn (MovementWorkbook $workbook): array => [
                'id' => $workbook->id,
                'name' => $workbook->name ?? "{$workbook->year} Movement Sheet",
                'mda' => $workbook->mda?->only(['id', 'code', 'name']),
                'year' => $workbook->year,
                'budget_year' => $workbook->budget_year,
                'budget_minimum_step' => $workbook->budget_minimum_step,
                'status' => $workbook->status,
                'summary' => $workbook->summary ?? [],
                'approval_status' => $workbook->approvalWorkflow?->status,
            ]),
            'options' => [
                'mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'code', 'name']),
            ],
        ]);
    }

    public function show(MovementWorkbook $workbook, MovementDepartmentSummaryService $departmentSummaryService): JsonResponse
    {
        $this->authorize('view', $workbook);

        $workbook->load([
            'mda',
            'summaries.department',
            'summaries.salaryScale',
            'approvalWorkflow.steps',
            'lines.staff.qualifications',
            'lines.currentEmployment.department',
            'lines.currentSalaryScale',
            'lines.proposedSalaryScale',
        ]);

        return response()->json([
            'data' => [
                'id' => $workbook->id,
                'name' => $workbook->name ?? "{$workbook->year} Movement Sheet",
                'mda' => $workbook->mda?->only(['id', 'code', 'name']),
                'year' => $workbook->year,
                'budget_year' => $workbook->budget_year,
                'budget_minimum_step' => $workbook->budget_minimum_step,
                'status' => $workbook->status,
                'summary' => $workbook->summary ?? [],
                'approval_workflow' => $workbook->approvalWorkflow,
                'department_summaries' => $departmentSummaryService->summarize($workbook),
                'lines' => $workbook->lines->map(fn ($line): array => [
                    'id' => $line->id,
                    'staff_id' => $line->staff_id,
                    'staff_number' => $line->staff?->staff_number,
                    'legacy_cno' => $line->staff?->legacy_cno,
                    'legacy_psn' => $line->staff?->legacy_psn,
                    'full_name' => $line->staff?->full_name,
                    'highest_qualification' => $line->staff?->qualifications->firstWhere('is_highest', true)?->highest_qualification_name
                        ?? $line->staff?->qualifications->firstWhere('is_highest', true)?->qualification_name,
                    'department_id' => $line->currentEmployment?->department_id,
                    'department' => $line->currentEmployment?->department?->name ?? 'Unassigned',
                    'date_last_promotion' => $line->currentEmployment?->date_last_promotion?->toDateString(),
                    'next_promotion_date' => $line->currentEmployment?->next_promotion_date?->toDateString(),
                    'current_placement' => $line->currentSalaryScale?->code
                        ? sprintf('%s %s/%s', $line->currentSalaryScale->code, $line->current_level ?? '-', $line->current_step ?? '-')
                        : null,
                    'proposed_placement' => $line->proposedSalaryScale?->code
                        ? sprintf('%s %s/%s', $line->proposedSalaryScale->code, $line->proposed_level ?? '-', $line->proposed_step ?? '-')
                        : null,
                    'selection_state' => $line->selection_state,
                    'eligibility_status' => $line->eligibility_status,
                    'retirement_status' => $line->retirement_status,
                ])->sortBy(fn (array $line): string => strtolower(($line['department'] ?? '').'|'.($line['full_name'] ?? '')))->values(),
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

    public function exportSummary(
        Request $request,
        MovementWorkbook $workbook,
        MovementDepartmentSummaryService $departmentSummaryService,
    ): BinaryFileResponse {
        $this->authorize('view', $workbook);
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer'],
        ]);
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $department = $departmentSummaryService->summarize($workbook)
            ->firstWhere('department_id', $departmentId);
        $suffix = $departmentId !== null && $department
            ? '-'.Str::slug($department['department'])
            : '';
        $filename = Str::slug($workbook->name ?? "{$workbook->year}-movement-sheet").$suffix.'-summary.xlsx';

        return Excel::download(
            new MovementSummaryExport($workbook, $departmentSummaryService, $departmentId),
            $filename,
        );
    }

    public function exportDetail(Request $request, MovementWorkbook $workbook): BinaryFileResponse
    {
        $this->authorize('view', $workbook);
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer'],
        ]);
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $department = $departmentId !== null
            ? $workbook->lines()->with('currentEmployment.department')->get()->first(fn ($line) => $line->currentEmployment?->department_id === $departmentId)?->currentEmployment?->department
            : null;
        $suffix = $department ? '-'.Str::slug($department->name) : '';
        $filename = Str::slug($workbook->name ?? "{$workbook->year}-movement-sheet").$suffix.'-detail.xlsx';

        return Excel::download(new MovementDetailExport($workbook, $departmentId), $filename);
    }
}
