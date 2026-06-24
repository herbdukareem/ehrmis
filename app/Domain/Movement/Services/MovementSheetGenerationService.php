<?php

namespace App\Domain\Movement\Services;

use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Services\PromotionPolicyService;
use App\Domain\Staff\Services\QualificationCeilingService;
use App\Domain\Staff\Services\RetirementPolicyService;
use App\Domain\Staff\Services\SalaryCalculationService;
use App\Domain\Staff\Services\StaffRetirementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MovementSheetGenerationService
{
    public function __construct(
        protected SalaryCalculationService $salaryCalculationService,
        protected PromotionPolicyService $promotionPolicyService,
        protected QualificationCeilingService $qualificationCeilingService,
        protected RetirementPolicyService $retirementPolicyService,
        protected StaffRetirementService $staffRetirementService,
        protected MovementSummaryService $movementSummaryService,
    ) {
    }

    public function generateForMda(
        int $mdaId,
        int $year,
        ?int $generatedBy = null,
        ?string $name = null,
        ?int $budgetYear = null,
        int $budgetMinimumStep = 5,
    ): MovementWorkbook
    {
        $workbook = $this->initializeWorkbook($mdaId, $year, $generatedBy, $name, $budgetYear, $budgetMinimumStep);

        $this->populateWorkbook($workbook, $year, $budgetYear ?? ($year + 1), $budgetMinimumStep);

        return $workbook->fresh(['lines', 'summaries']);
    }

    /**
     * Validate and (re)create the workbook shell synchronously. This is intentionally cheap -
     * it does not touch staff records - so it can run inline in an HTTP request before the
     * actual line generation is handed off to a queued job.
     */
    public function initializeWorkbook(
        int $mdaId,
        int $year,
        ?int $generatedBy = null,
        ?string $name = null,
        ?int $budgetYear = null,
        int $budgetMinimumStep = 5,
    ): MovementWorkbook
    {
        $mda = Mda::query()->findOrFail($mdaId);

        return DB::transaction(function () use ($mda, $year, $generatedBy, $name, $budgetYear, $budgetMinimumStep): MovementWorkbook {
            $existingWorkbook = MovementWorkbook::query()
                ->where('mda_id', $mda->id)
                ->where('year', $year)
                ->first();

            if ($existingWorkbook && in_array($existingWorkbook->status, ['approved', 'locked'], true)) {
                throw new \InvalidArgumentException('Approved or locked movement workbooks must be reopened before regeneration.');
            }

            $workbook = MovementWorkbook::query()->updateOrCreate(
                [
                    'mda_id' => $mda->id,
                    'year' => $year,
                ],
                [
                    'name' => $name ?? "{$year} Movement Sheet",
                    'budget_year' => $budgetYear ?? ($year + 1),
                    'budget_minimum_step' => $budgetMinimumStep,
                    'status' => 'generating',
                    'generated_by' => $generatedBy,
                    'generated_at' => now(),
                    'locked_at' => null,
                    'summary' => null,
                ],
            );

            $workbook->lines()->delete();

            return $workbook;
        });
    }

    /**
     * Run the heavy per-staff line generation for an already-initialized workbook. This is the
     * part that scales with staff headcount, so callers running it inline (the console command,
     * data repair tooling) accept the cost, while the API path runs it inside a queued job to
     * stay clear of the web server's request timeout.
     */
    public function populateWorkbook(MovementWorkbook $workbook, int $year, int $budgetYear, int $budgetMinimumStep): void
    {
        DB::transaction(function () use ($workbook, $year, $budgetYear, $budgetMinimumStep): void {
            $staffMembers = Staff::query()
                ->forMda($workbook->mda_id)
                ->with([
                    'employments' => fn ($query) => $query->where('is_current', true),
                    'salaryPlacements' => fn ($query) => $query->where('is_current', true)->with('salaryScale'),
                    'allowanceAssignments.allowanceType',
                    'qualifications.qualificationType',
                ])
                ->whereNull('deleted_at')
                ->orderBy('id');

            $summary = [
                'staff_considered' => 0,
                'lines_generated' => 0,
                'due_for_promotion' => 0,
                'retiring_in_year' => 0,
                'already_retired' => 0,
                'blocked' => 0,
            ];

            $now = now();

            $staffMembers->chunkById(200, function ($staffChunk) use (&$summary, $workbook, $year, $budgetYear, $budgetMinimumStep, $now): void {
                $rows = [];

                foreach ($staffChunk as $staff) {
                    $summary['staff_considered']++;
                    $movementLine = $this->generateLinePayload($staff, $year, $budgetYear, $budgetMinimumStep);

                    if ($movementLine['eligibility_status'] === 'due') {
                        $summary['due_for_promotion']++;
                    }

                    if ($movementLine['retirement_status'] === 'retiring') {
                        $summary['retiring_in_year']++;
                    }

                    if ($movementLine['retirement_status'] === 'retired') {
                        $summary['already_retired']++;
                    }

                    if ($movementLine['eligibility_status'] === 'blocked_by_policy') {
                        $summary['blocked']++;
                    }

                    $rows[] = array_merge(
                        ['workbook_id' => $workbook->id, 'staff_id' => $staff->id],
                        $this->encodeLineForBulkInsert($movementLine),
                        ['created_at' => $now, 'updated_at' => $now],
                    );

                    $summary['lines_generated']++;
                }

                if ($rows !== []) {
                    MovementLine::query()->insert($rows);
                }
            });

            $this->movementSummaryService->regenerate($workbook);

            $workbook->forceFill([
                'status' => 'draft',
                'summary' => $summary,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function encodeLineForBulkInsert(array $line): array
    {
        $line['current_amounts'] = json_encode($line['current_amounts']);
        $line['proposed_amounts'] = json_encode($line['proposed_amounts']);
        $line['decision_trace'] = json_encode($line['decision_trace']);

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    protected function generateLinePayload(Staff $staff, int $year, int $budgetYear, int $budgetMinimumStep): array
    {
        /** @var StaffEmployment|null $employment */
        $employment = $staff->employments->first();
        /** @var StaffSalaryPlacement|null $placement */
        $placement = $staff->salaryPlacements->first();

        $eligibleAllowanceCodes = $staff->allowanceAssignments
            ->filter(fn ($assignment): bool => (bool) $assignment->is_eligible && $assignment->allowanceType !== null)
            ->map(fn ($assignment): string => (string) $assignment->allowanceType->code)
            ->values()
            ->all();

        $currentScaleCode = $placement?->salaryScale?->code;
        $currentLevel = $placement?->level;
        $currentStep = $placement?->step;

        $currentAmounts = $currentScaleCode !== null && $currentLevel !== null && $currentStep !== null
            ? $this->salaryCalculationService->calculateGrossForPlacement($currentScaleCode, $currentLevel, $currentStep, $eligibleAllowanceCodes, (int) $staff->mda_id)
            : [
                'basic_salary' => $placement?->basic_salary !== null ? (float) $placement->basic_salary : null,
                'allowance_breakdown' => [],
                'total_allowances' => null,
                'calculated_gross' => $placement?->gross_salary !== null ? (float) $placement->gross_salary : null,
                'legacy_gross_salary' => null,
                'gross_difference' => null,
            ];

        $retirementDate = $employment?->expected_retirement_date
            ?? $this->retirementPolicyService->calculateExpectedRetirementDate(
                $staff->date_of_birth ? Carbon::parse($staff->date_of_birth) : null,
                $employment?->date_first_appointment ? Carbon::parse($employment->date_first_appointment) : null,
            );

        $retirementStatus = 'active';
        $retirementMonth = null;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        $promotionAssessmentDate = Carbon::create($budgetYear, 12, 31)->endOfDay();

        if ($retirementDate instanceof Carbon && $retirementDate->lt($startOfYear)) {
            $retirementStatus = 'retired';
        } elseif ($retirementDate instanceof Carbon && $retirementDate->betweenIncluded($startOfYear, $endOfYear)) {
            $retirementStatus = 'retiring';
            $retirementMonth = $retirementDate->month;
        } elseif ($this->staffRetirementService->isRetired(
            $staff->status,
            $employment?->employment_status,
            $retirementDate,
            $startOfYear,
        )) {
            $retirementStatus = 'retired';
        }

        $promotionDue = false;
        $proposedLevel = $currentLevel;
        $proposedStep = $currentStep;
        $eligibilityStatus = 'not_due';
        $nextPromotionDate = $employment?->next_promotion_date
            ? Carbon::parse($employment->next_promotion_date)
            : null;

        if ($currentScaleCode !== null && $currentLevel !== null && $currentStep !== null && $retirementStatus === 'active') {
            if ($nextPromotionDate === null && $employment?->date_last_promotion) {
                $nextPromotionDate = $this->promotionPolicyService->calculateNextPromotionDate(
                    Carbon::parse($employment->date_last_promotion),
                    $currentScaleCode,
                    $currentLevel,
                );
            }

            $promotionDue = $nextPromotionDate?->lte($promotionAssessmentDate) ?? false;

            if ($promotionDue) {
                $candidateLevel = $this->nextPromotionLevel($currentScaleCode, $currentLevel);
                $candidateStep = max($currentStep, $budgetMinimumStep);
                $scaleMaxLevel = $placement?->salaryScale?->max_level;
                $qualificationCode = $staff->qualifications
                    ->firstWhere('is_highest', true)?->qualificationType?->code;
                $qualificationMaxLevel = $qualificationCode
                    ? $this->qualificationCeilingService->getMaxLevelFor($qualificationCode, $currentScaleCode)
                    : null;
                $withinScale = $scaleMaxLevel === null || $candidateLevel <= $scaleMaxLevel;
                $withinQualification = $qualificationMaxLevel === null || $candidateLevel <= $qualificationMaxLevel;

                if ($withinScale && $withinQualification) {
                    $proposedLevel = $candidateLevel;
                    $proposedStep = $candidateStep;
                    $eligibilityStatus = 'due';
                } else {
                    $eligibilityStatus = 'blocked_by_policy';
                }
            }
        } elseif ($retirementStatus !== 'active') {
            $eligibilityStatus = 'retiring';
        } else {
            $eligibilityStatus = 'blocked_by_policy';
        }

        if ($retirementStatus === 'retiring') {
            $eligibilityStatus = 'retiring';
        }

        if ($retirementStatus === 'retired') {
            $eligibilityStatus = 'retired';
        }

        $proposedAmounts = $currentScaleCode !== null && $proposedLevel !== null && $proposedStep !== null
            ? $this->salaryCalculationService->calculateGrossForPlacement($currentScaleCode, $proposedLevel, $proposedStep, $eligibleAllowanceCodes, (int) $staff->mda_id)
            : $currentAmounts;

        return [
            'current_employment_id' => $employment?->id,
            'current_salary_placement_id' => $placement?->id,
            'current_salary_scale_id' => $placement?->salary_scale_id,
            'proposed_salary_scale_id' => $placement?->salary_scale_id,
            'selection_state' => $retirementStatus === 'retired' ? 'excluded' : 'included',
            'eligibility_status' => $eligibilityStatus,
            'retirement_status' => $retirementStatus,
            'retirement_month' => $retirementMonth,
            'current_level' => $currentLevel,
            'current_step' => $currentStep,
            'proposed_level' => $proposedLevel,
            'proposed_step' => $proposedStep,
            'current_amounts' => array_merge($currentAmounts, [
                'salary_scale_code' => $currentScaleCode,
            ]),
            'proposed_amounts' => array_merge($proposedAmounts, [
                'salary_scale_code' => $currentScaleCode,
            ]),
            'decision_trace' => [
                'eligible_allowance_codes' => $eligibleAllowanceCodes,
                'promotion_due' => $promotionDue,
                'next_promotion_date' => $nextPromotionDate?->toDateString(),
                'promotion_assessment_date' => $promotionAssessmentDate->toDateString(),
                'budget_minimum_step' => $budgetMinimumStep,
                'retirement_date' => $retirementDate?->toDateString(),
                'current_status' => $staff->status,
                'employment_status' => $employment?->employment_status,
            ],
            'calculation_source' => 'salary_calculation_service',
        ];
    }

    protected function nextPromotionLevel(string $salaryScaleCode, int $currentLevel): int
    {
        $nextLevel = $currentLevel + 1;

        return $salaryScaleCode === 'GL' && $nextLevel === 11 ? 12 : $nextLevel;
    }
}
