<?php

namespace App\Domain\Movement\Services;

use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Domain\Staff\Services\PromotionPolicyService;
use App\Domain\Staff\Services\RetirementPolicyService;
use App\Domain\Staff\Services\SalaryCalculationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MovementSheetGenerationService
{
    public function __construct(
        protected SalaryCalculationService $salaryCalculationService,
        protected PromotionPolicyService $promotionPolicyService,
        protected RetirementPolicyService $retirementPolicyService,
        protected MovementSummaryService $movementSummaryService,
    ) {
    }

    public function generateForMda(int $mdaId, int $year, ?int $generatedBy = null): MovementWorkbook
    {
        $mda = Mda::query()->findOrFail($mdaId);

        return DB::transaction(function () use ($mda, $year, $generatedBy): MovementWorkbook {
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
                    'status' => 'draft',
                    'generated_by' => $generatedBy,
                    'generated_at' => now(),
                    'locked_at' => null,
                ],
            );

            $workbook->lines()->delete();

            $staffMembers = Staff::withoutGlobalScopes()
                ->with([
                    'employments' => fn ($query) => $query->where('is_current', true),
                    'salaryPlacements' => fn ($query) => $query->where('is_current', true)->with('salaryScale'),
                    'allowanceAssignments.allowanceType',
                ])
                ->where('mda_id', $mda->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();

            $summary = [
                'staff_considered' => 0,
                'lines_generated' => 0,
                'due_for_promotion' => 0,
                'retiring_in_year' => 0,
                'already_retired' => 0,
                'blocked' => 0,
            ];

            foreach ($staffMembers as $staff) {
                $summary['staff_considered']++;
                $movementLine = $this->generateLinePayload($staff, $year);

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

                MovementLine::query()->updateOrCreate(
                    [
                        'workbook_id' => $workbook->id,
                        'staff_id' => $staff->id,
                    ],
                    $movementLine,
                );

                $summary['lines_generated']++;
            }

            $this->movementSummaryService->regenerate($workbook);

            $workbook->forceFill([
                'summary' => $summary,
            ])->save();

            return $workbook->fresh(['lines', 'summaries']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function generateLinePayload(Staff $staff, int $year): array
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
            ? $this->salaryCalculationService->calculateGrossForPlacement($currentScaleCode, $currentLevel, $currentStep, $eligibleAllowanceCodes)
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

        if ($retirementDate instanceof Carbon && $retirementDate->lt($startOfYear)) {
            $retirementStatus = 'retired';
        } elseif ($retirementDate instanceof Carbon && $retirementDate->betweenIncluded($startOfYear, $endOfYear)) {
            $retirementStatus = 'retiring';
            $retirementMonth = $retirementDate->month;
        } elseif (($employment?->employment_status ?? null) === 'retired' || $staff->status === 'retired') {
            $retirementStatus = 'retired';
        }

        $promotionDue = false;
        $proposedLevel = $currentLevel;
        $proposedStep = $currentStep;
        $eligibilityStatus = 'not_due';

        if ($currentScaleCode !== null && $currentLevel !== null && $currentStep !== null && $retirementStatus === 'active') {
            if ($employment?->date_last_promotion) {
                $promotionDue = $this->promotionPolicyService->isPromotionDue(
                    Carbon::parse($employment->date_last_promotion),
                    $currentScaleCode,
                    $currentLevel,
                    $endOfYear,
                );
            } elseif ($employment?->next_promotion_date) {
                $promotionDue = Carbon::parse($employment->next_promotion_date)->lte($endOfYear);
            }

            if ($promotionDue) {
                $candidateLevel = $currentLevel + 1;
                $candidateRate = $this->salaryCalculationService->getRate($currentScaleCode, $candidateLevel, $currentStep);

                if ($candidateRate) {
                    $proposedLevel = $candidateLevel;
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
            ? $this->salaryCalculationService->calculateGrossForPlacement($currentScaleCode, $proposedLevel, $proposedStep, $eligibleAllowanceCodes)
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
                'retirement_date' => $retirementDate?->toDateString(),
                'current_status' => $staff->status,
                'employment_status' => $employment?->employment_status,
            ],
            'calculation_source' => 'salary_calculation_service',
        ];
    }
}
