<?php

namespace App\Http\Controllers\Api;

use App\Domain\Movement\Exports\MovementSummaryExport;
use App\Domain\Movement\Exports\MovementDetailExport;
use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementDepartmentSummaryService;
use App\Domain\Movement\Services\MovementSummaryService;
use App\Domain\Movement\Services\MovementSheetGenerationService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Services\SalaryCalculationService;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateMovementWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
                'line_count' => $this->visibleMovementLineCount($workbook),
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
                'lines' => $workbook->lines
                    ->filter(fn (MovementLine $line): bool => $this->shouldShowMovementLine($line))
                    ->map(fn (MovementLine $line): array => $this->movementLinePayload($line))
                    ->sortBy(fn (array $line): string => strtolower(($line['department'] ?? '').'|'.($line['full_name'] ?? '')))
                    ->values(),
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

    protected function shouldShowMovementLine(MovementLine $line): bool
    {
        return in_array($line->retirement_status, ['active', 'retiring'], true)
            || $this->effectiveContractStaff($line);
    }

    protected function visibleMovementLineCount(MovementWorkbook $workbook): int
    {
        return $workbook->lines()
            ->where(function ($query): void {
                $query
                    ->whereIn('retirement_status', ['active', 'retiring'])
                    ->orWhere('is_contract_staff', true)
                    ->orWhereHas('staff', fn ($staffQuery) => $staffQuery->where('is_contract_staff', true));
            })
            ->count();
    }

    protected function effectiveContractStaff(MovementLine $line): bool
    {
        return (bool) ($line->staff?->is_contract_staff ?? $line->is_contract_staff);
    }

    protected function movementLinePayload(MovementLine $line): array
    {
        $isContractStaff = $this->effectiveContractStaff($line);

        return [
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
            'date_first_appointment' => $line->currentEmployment?->date_first_appointment?->toDateString(),
            'date_last_promotion' => $line->currentEmployment?->date_last_promotion?->toDateString(),
            'next_promotion_date' => $line->currentEmployment?->next_promotion_date?->toDateString(),
            'current_placement' => $line->currentSalaryScale?->code
                ? sprintf('%s %s/%s', $line->currentSalaryScale->code, $line->current_level ?? '-', $line->current_step ?? '-')
                : null,
            'proposed_placement' => $line->proposedSalaryScale?->code
                ? sprintf('%s %s/%s', $line->proposedSalaryScale->code, $line->proposed_level ?? '-', $line->proposed_step ?? '-')
                : null,
            'current_level' => $line->current_level,
            'current_step' => $line->current_step,
            'proposed_level' => $line->proposed_level,
            'proposed_step' => $line->proposed_step,
            'selection_state' => $line->selection_state,
            'eligibility_status' => $isContractStaff ? 'contract' : $line->eligibility_status,
            'eligibility_reason' => $isContractStaff
                ? 'Staff is marked as contract staff on the staff record.'
                : ($line->decision_trace['eligibility_reason'] ?? null),
            'retirement_status' => $line->retirement_status,
            'is_contract_staff' => $isContractStaff,
            'is_special_movement' => (bool) $line->is_special_movement,
        ];
    }

    public function updateLineFlags(
        Request $request,
        MovementWorkbook $workbook,
        MovementLine $line,
        MovementSummaryService $summaryService,
        SalaryCalculationService $salaryCalculationService,
    ): JsonResponse {
        $this->authorize('review', $workbook);
        abort_unless((int) $line->workbook_id === (int) $workbook->id, 404);
        abort_if(in_array($workbook->status, ['approved', 'locked'], true), 422, 'Reopen this movement workbook before changing staff movement flags.');

        $validated = $request->validate([
            'is_contract_staff' => ['required', 'boolean'],
            'is_special_movement' => ['required', 'boolean'],
            'proposed_level' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $isContract = (bool) $validated['is_contract_staff'];
        $isSpecial = (bool) $validated['is_special_movement'];

        if ($isContract && $isSpecial) {
            throw ValidationException::withMessages([
                'is_special_movement' => 'A movement line can be contract staff or special movement, not both.',
            ]);
        }

        if ($isSpecial && empty($validated['proposed_level'])) {
            throw ValidationException::withMessages([
                'proposed_level' => 'Select the level this staff should move to.',
            ]);
        }

        $line->load(['staff.allowanceAssignments.allowanceType', 'currentSalaryScale', 'proposedSalaryScale']);
        $isOverride = $isContract || $isSpecial;
        $decisionTrace = $line->decision_trace ?? [];
        $attributes = [
            'is_contract_staff' => $isContract,
            'is_special_movement' => $isSpecial,
            'selection_state' => $line->retirement_status === 'retired'
                ? ($isOverride ? 'included' : 'excluded')
                : 'included',
        ];

        if ($isSpecial) {
            $salaryScale = $line->proposedSalaryScale ?? $line->currentSalaryScale;
            $targetLevel = (int) $validated['proposed_level'];
            $minLevel = (int) ($salaryScale?->min_level ?? 1);
            $maxLevel = (int) ($salaryScale?->max_level ?? 20);

            if ($targetLevel < $minLevel || $targetLevel > $maxLevel) {
                throw ValidationException::withMessages([
                    'proposed_level' => "Selected level must be between {$minLevel} and {$maxLevel}.",
                ]);
            }

            $targetStep = max((int) ($line->current_step ?? 1), (int) ($workbook->budget_minimum_step ?? 1));
            $attributes['eligibility_status'] = 'due';
            $attributes['proposed_salary_scale_id'] = $salaryScale?->id ?? $line->current_salary_scale_id;
            $attributes['proposed_level'] = $targetLevel;
            $attributes['proposed_step'] = $targetStep;
            $attributes['proposed_amounts'] = $this->calculateMovementAmounts($line, $salaryCalculationService, $salaryScale?->code, $targetLevel, $targetStep);
            $decisionTrace['eligibility_reason'] = 'Staff was manually marked as special movement.';
            $decisionTrace['special_movement_level'] = $targetLevel;
        } elseif ($isContract) {
            $attributes['eligibility_status'] = 'contract';
            $decisionTrace['eligibility_reason'] = 'Staff was manually marked as contract staff.';
            unset($decisionTrace['special_movement_level']);
        } else {
            $attributes['eligibility_status'] = $this->defaultEligibilityStatus($line);
            $decisionTrace['eligibility_reason'] = $this->defaultEligibilityReason($line);
            unset($decisionTrace['special_movement_level']);
        }

        $attributes['decision_trace'] = $decisionTrace;

        $line->forceFill($attributes)->save();
        $line->staff?->forceFill(['is_contract_staff' => $isContract])->save();

        $summaryService->regenerate($workbook);
        $line->refresh()->load(['currentSalaryScale', 'proposedSalaryScale']);

        return response()->json([
            'message' => 'Movement flags updated.',
            'data' => [
                'id' => $line->id,
                'is_contract_staff' => (bool) $line->is_contract_staff,
                'is_special_movement' => (bool) $line->is_special_movement,
                'proposed_level' => $line->proposed_level,
                'proposed_step' => $line->proposed_step,
                'proposed_placement' => $line->proposedSalaryScale?->code
                    ? sprintf('%s %s/%s', $line->proposedSalaryScale->code, $line->proposed_level ?? '-', $line->proposed_step ?? '-')
                    : null,
                'eligibility_status' => $line->eligibility_status,
                'eligibility_reason' => $line->decision_trace['eligibility_reason'] ?? null,
                'selection_state' => $line->selection_state,
            ],
        ]);
    }

    protected function calculateMovementAmounts(
        MovementLine $line,
        SalaryCalculationService $salaryCalculationService,
        ?string $scaleCode,
        ?int $level,
        ?int $step,
    ): array {
        if ($scaleCode === null || $level === null || $step === null) {
            return $line->proposed_amounts ?? $line->current_amounts ?? [];
        }

        $eligibleAllowanceCodes = $line->staff?->allowanceAssignments
            ->filter(fn ($assignment): bool => (bool) $assignment->is_eligible && $assignment->allowanceType !== null)
            ->pluck('allowanceType.code')
            ->filter()
            ->values()
            ->all() ?? [];

        $calculation = $salaryCalculationService->calculateGrossForPlacement($scaleCode, $level, $step, $eligibleAllowanceCodes, (int) $line->staff?->mda_id);

        if ($calculation['calculated_gross'] === null) {
            return $line->proposed_amounts ?? $line->current_amounts ?? [];
        }

        return array_merge($calculation, ['salary_scale_code' => $scaleCode]);
    }

    protected function defaultEligibilityStatus(MovementLine $line): string
    {
        if ($line->retirement_status === 'retired') {
            return 'retired';
        }

        if ($line->retirement_status === 'retiring') {
            return 'retiring';
        }

        return $line->current_salary_scale_id !== $line->proposed_salary_scale_id
            || (int) $line->current_level !== (int) $line->proposed_level
            || (int) $line->current_step !== (int) $line->proposed_step
                ? 'due'
                : 'not_due';
    }

    protected function defaultEligibilityReason(MovementLine $line): ?string
    {
        if ($line->retirement_status === 'retired') {
            return 'Staff is already retired before the movement year.';
        }

        if ($line->retirement_status === 'retiring') {
            return 'Staff is retiring within the movement year.';
        }

        return null;
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
        $department = $departmentId !== null
            ? $departmentSummaryService->summarize($workbook)->firstWhere('department_id', $departmentId)
            : null;
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
