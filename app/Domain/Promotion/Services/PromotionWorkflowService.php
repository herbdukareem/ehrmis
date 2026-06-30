<?php

namespace App\Domain\Promotion\Services;

use App\Domain\Approval\Services\ApprovalWorkflowService;
use App\Domain\Promotion\Models\PromotionApplication;
use App\Domain\Promotion\Models\PromotionCycle;
use App\Domain\Promotion\Models\PromotionLetter;
use App\Domain\Promotion\Models\PromotionSitting;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OfficialLetterPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PromotionWorkflowService
{
    protected const PRINT_WORKFLOW_TYPE = 'promotion_sitting_print_authorization';

    public function __construct(
        protected ApprovalWorkflowService $approvalWorkflowService,
        protected AuditLogService $auditLogService,
        protected OfficialLetterPdfService $letterPdfService,
    ) {
    }

    public function createCycle(array $data, User $actor): PromotionCycle
    {
        return DB::transaction(function () use ($data, $actor): PromotionCycle {
            $cycle = PromotionCycle::query()->create([
                'mda_id' => $data['mda_id'] ?? null,
                'title' => $data['title'],
                'year' => (int) $data['year'],
                'opens_at' => $data['opens_at'] ?? null,
                'closes_at' => $data['closes_at'] ?? null,
                'status' => $data['status'] ?? 'open',
                'created_by' => $actor->id,
            ]);

            $this->auditLogService->logCreated($cycle, ['source' => 'promotion_cycle.create']);

            return $cycle->fresh(['mda']);
        });
    }

    public function submitApplication(PromotionCycle $cycle, array $data): PromotionApplication
    {
        if ($cycle->status !== 'open') {
            throw new InvalidArgumentException('This promotion cycle is not open for applications.');
        }

        return DB::transaction(function () use ($cycle, $data): PromotionApplication {
            $mdaId = (int) $data['mda_id'];
            if ($cycle->mda_id !== null && (int) $cycle->mda_id !== $mdaId) {
                throw new InvalidArgumentException('The selected MDA is not part of this promotion cycle.');
            }

            $staff = $this->findMatchingStaff($mdaId, $data);

            if ($staff && PromotionApplication::query()
                ->where('cycle_id', $cycle->id)
                ->where('mda_id', $mdaId)
                ->where('staff_id', $staff->id)
                ->whereNotIn('status', ['rejected', 'cancelled'])
                ->exists()) {
                throw new InvalidArgumentException('A promotion application already exists for this staff member in this cycle.');
            }

            $application = PromotionApplication::query()->create([
                'cycle_id' => $cycle->id,
                'mda_id' => $mdaId,
                'staff_id' => $staff?->id,
                'application_number' => $this->makeApplicationNumber($cycle, $mdaId),
                'staff_number' => $data['staff_number'] ?? $staff?->staff_number,
                'legacy_cno' => $data['legacy_cno'] ?? $staff?->legacy_cno,
                'legacy_psn' => $data['legacy_psn'] ?? $staff?->legacy_psn,
                'surname' => $data['surname'] ?? $staff?->surname,
                'first_name' => $data['first_name'] ?? $staff?->first_name,
                'middle_name' => $data['middle_name'] ?? $staff?->middle_name,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'applicant_remarks' => $data['applicant_remarks'] ?? null,
                'current_snapshot' => $staff ? $this->staffSnapshot($staff) : null,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            $this->auditLogService->log('promotion_application.submitted', $application, [], $application->toArray(), [
                'cycle_id' => $cycle->id,
                'mda_id' => $mdaId,
                'staff_id' => $staff?->id,
            ]);

            return $application->fresh(['cycle', 'mda', 'staff.currentEmployment.rank', 'staff.currentSalaryPlacement.salaryScale']);
        });
    }

    public function screen(PromotionApplication $application, array $data, User $actor): PromotionApplication
    {
        if (! in_array($application->status, ['submitted', 'screened', 'listed_for_sitting'], true)) {
            throw new InvalidArgumentException('This promotion application cannot be screened in its current state.');
        }

        return DB::transaction(function () use ($application, $data, $actor): PromotionApplication {
            $application = PromotionApplication::query()->lockForUpdate()->findOrFail($application->id);
            $before = $application->toArray();
            $staff = $application->staff?->load(['currentEmployment.rank', 'currentSalaryPlacement.salaryScale']);

            $proposedRank = isset($data['proposed_rank_id'])
                ? Rank::query()->with('salaryScale')->find((int) $data['proposed_rank_id'])
                : null;
            $proposedScaleId = $data['proposed_salary_scale_id'] ?? $proposedRank?->salary_scale_id;
            $proposedScale = $proposedScaleId ? SalaryScale::query()->find((int) $proposedScaleId) : null;

            if ($proposedRank?->salaryScale && (int) $proposedRank->salaryScale->mda_id !== (int) $application->mda_id) {
                throw new InvalidArgumentException('The proposed rank must belong to the application MDA.');
            }

            if ($proposedScale && (int) $proposedScale->mda_id !== (int) $application->mda_id) {
                throw new InvalidArgumentException('The proposed salary scale must belong to the application MDA.');
            }

            $application->fill([
                'staff_id' => $data['staff_id'] ?? $application->staff_id,
                'current_snapshot' => $staff ? $this->staffSnapshot($staff) : $application->current_snapshot,
                'current_rank_id' => $data['current_rank_id'] ?? $staff?->currentEmployment?->rank_id ?? $application->current_rank_id,
                'current_salary_scale_id' => $data['current_salary_scale_id'] ?? $staff?->currentSalaryPlacement?->salary_scale_id ?? $application->current_salary_scale_id,
                'current_level' => $data['current_level'] ?? $staff?->currentSalaryPlacement?->level ?? $application->current_level,
                'current_step' => $data['current_step'] ?? $staff?->currentSalaryPlacement?->step ?? $application->current_step,
                'proposed_rank_id' => $proposedRank?->id ?? ($data['proposed_rank_id'] ?? $application->proposed_rank_id),
                'proposed_salary_scale_id' => $proposedScale?->id ?? $application->proposed_salary_scale_id,
                'proposed_level' => $data['proposed_level'] ?? $proposedRank?->level ?? $application->proposed_level,
                'proposed_step' => $data['proposed_step'] ?? $application->proposed_step,
                'status' => $data['status'] ?? 'screened',
                'screened_by' => $actor->id,
                'screened_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($application, $before, ['source' => 'promotion_application.screened']);

            return $application->fresh(['cycle', 'mda', 'staff', 'proposedRank', 'proposedSalaryScale']);
        });
    }

    public function createSitting(PromotionCycle $cycle, array $data, User $actor): PromotionSitting
    {
        return DB::transaction(function () use ($cycle, $data, $actor): PromotionSitting {
            $mdaId = (int) $data['mda_id'];
            if ($cycle->mda_id !== null && (int) $cycle->mda_id !== $mdaId) {
                throw new InvalidArgumentException('The selected MDA is not part of this promotion cycle.');
            }

            $sitting = PromotionSitting::query()->create([
                'cycle_id' => $cycle->id,
                'mda_id' => $mdaId,
                'title' => $data['title'],
                'sitting_date' => $data['sitting_date'],
                'panel_notes' => $data['panel_notes'] ?? null,
                'created_by' => $actor->id,
                'status' => 'draft',
            ]);

            $this->auditLogService->logCreated($sitting, ['source' => 'promotion_sitting.create']);

            return $sitting->fresh(['cycle', 'mda']);
        });
    }

    public function decide(PromotionSitting $sitting, PromotionApplication $application, array $data, User $actor): PromotionApplication
    {
        if (! in_array($sitting->status, ['draft', 'in_review'], true)) {
            throw new InvalidArgumentException('This sitting is not open for decisions.');
        }

        $decision = $data['decision'];
        if (! in_array($decision, ['approved', 'approved_with_corrections', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid promotion decision.');
        }

        return DB::transaction(function () use ($sitting, $application, $data, $actor, $decision): PromotionApplication {
            $sitting = PromotionSitting::query()->lockForUpdate()->findOrFail($sitting->id);
            $application = PromotionApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ((int) $application->cycle_id !== (int) $sitting->cycle_id || (int) $application->mda_id !== (int) $sitting->mda_id) {
                throw new InvalidArgumentException('This application does not belong to the selected sitting.');
            }

            $before = $application->toArray();

            $sitting->decisions()->updateOrCreate(
                ['application_id' => $application->id],
                [
                    'decision' => $decision,
                    'remarks' => $data['remarks'] ?? null,
                    'correction_notes' => $data['correction_notes'] ?? null,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ],
            );

            $application->forceFill([
                'sitting_id' => $sitting->id,
                'decision' => $decision,
                'decision_remarks' => $data['remarks'] ?? null,
                'correction_notes' => $data['correction_notes'] ?? null,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'status' => $decision,
            ])->save();

            if ($sitting->status === 'draft') {
                $sitting->forceFill(['status' => 'in_review'])->save();
            }

            $this->auditLogService->logUpdated($application, $before, ['source' => 'promotion_sitting.decision']);

            return $application->fresh(['sitting', 'decisionRecord', 'letter']);
        });
    }

    public function completeSitting(PromotionSitting $sitting, User $actor): PromotionSitting
    {
        if (! in_array($sitting->status, ['draft', 'in_review'], true)) {
            throw new InvalidArgumentException('Only draft or in-review sittings can be completed.');
        }

        return DB::transaction(function () use ($sitting, $actor): PromotionSitting {
            $before = $sitting->load('decisions')->toArray();
            $sitting->forceFill([
                'status' => 'completed',
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($sitting, $before, ['source' => 'promotion_sitting.completed']);

            return $sitting->fresh(['applications', 'decisions', 'approvalWorkflow.steps']);
        });
    }

    public function submitPrintApproval(PromotionSitting $sitting, User $actor): PromotionSitting
    {
        if ($sitting->status !== 'completed') {
            throw new InvalidArgumentException('Only completed sittings can be submitted for print approval.');
        }

        return DB::transaction(function () use ($sitting, $actor): PromotionSitting {
            $before = $sitting->load('approvalWorkflow.steps')->toArray();

            $this->approvalWorkflowService->submit(
                $sitting,
                self::PRINT_WORKFLOW_TYPE,
                $actor,
                [['reviewer_role' => 'MDA Admin']],
                ['mda_id' => $sitting->mda_id, 'cycle_id' => $sitting->cycle_id],
            );

            $sitting->forceFill(['status' => 'print_approval_pending'])->save();

            $this->auditLogService->logUpdated($sitting, $before, ['source' => 'promotion_sitting.print_submitted']);

            return $sitting->fresh(['approvalWorkflow.steps']);
        });
    }

    public function approvePrint(PromotionSitting $sitting, User $actor, ?string $comment = null): PromotionSitting
    {
        if ($sitting->status !== 'print_approval_pending' || ! $sitting->approvalWorkflow) {
            throw new InvalidArgumentException('This sitting has not been submitted for print approval.');
        }

        return DB::transaction(function () use ($sitting, $actor, $comment): PromotionSitting {
            $before = $sitting->load('approvalWorkflow.steps')->toArray();
            $this->approvalWorkflowService->approveStep($sitting->approvalWorkflow, $actor, $comment);

            $sitting->forceFill([
                'status' => 'print_authorized',
                'print_authorized_by' => $actor->id,
                'print_authorized_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($sitting, $before, ['source' => 'promotion_sitting.print_authorized']);

            return $sitting->fresh(['approvalWorkflow.steps']);
        });
    }

    public function rejectPrint(PromotionSitting $sitting, User $actor, string $comment): PromotionSitting
    {
        if ($sitting->status !== 'print_approval_pending' || ! $sitting->approvalWorkflow) {
            throw new InvalidArgumentException('This sitting has not been submitted for print approval.');
        }

        return DB::transaction(function () use ($sitting, $actor, $comment): PromotionSitting {
            $before = $sitting->load('approvalWorkflow.steps')->toArray();
            $this->approvalWorkflowService->reject($sitting->approvalWorkflow, $actor, $comment);
            $sitting->forceFill(['status' => 'completed'])->save();
            $this->auditLogService->logUpdated($sitting, $before, ['source' => 'promotion_sitting.print_rejected']);

            return $sitting->fresh(['approvalWorkflow.steps']);
        });
    }

    public function printLetter(PromotionApplication $application, User $actor): PromotionLetter
    {
        if (! in_array($application->status, ['approved', 'approved_with_corrections'], true)) {
            throw new InvalidArgumentException('Only approved promotion applications can be printed.');
        }

        if (! $application->sitting || $application->sitting->status !== 'print_authorized') {
            throw new InvalidArgumentException('The MDA head must authorize printing for the sitting first.');
        }

        return DB::transaction(function () use ($application, $actor): PromotionLetter {
            $application = PromotionApplication::query()
                ->with(['sitting', 'staff.currentEmployment', 'staff.currentSalaryPlacement'])
                ->lockForUpdate()
                ->findOrFail($application->id);

            $letter = PromotionLetter::query()->firstOrCreate(
                ['application_id' => $application->id],
                [
                    'letter_number' => $this->makeLetterNumber($application),
                    'effective_date' => $application->sitting->sitting_date,
                    'status' => 'generated',
                    'generated_by' => $actor->id,
                    'generated_at' => now(),
                ],
            );

            $before = $application->toArray();
            $this->applyPromotionToStaff($application, $actor);
            $letter = $this->letterPdfService->renderPromotionLetter($letter);

            $letter->forceFill([
                'status' => 'printed',
                'printed_by' => $actor->id,
                'printed_at' => now(),
            ])->save();

            $application->forceFill([
                'status' => 'letter_printed',
                'letter_printed_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($application, $before, ['source' => 'promotion_letter.printed']);

            return $letter->fresh(['application.staff']);
        });
    }

    protected function findMatchingStaff(int $mdaId, array $data): ?Staff
    {
        return Staff::query()
            ->where('mda_id', $mdaId)
            ->where(function (Builder $query) use ($data): void {
                foreach (['staff_number', 'legacy_cno', 'legacy_psn'] as $field) {
                    if (! empty($data[$field])) {
                        $query->orWhere($field, $data[$field]);
                    }
                }
            })
            ->with(['currentEmployment.rank', 'currentSalaryPlacement.salaryScale'])
            ->first();
    }

    protected function staffSnapshot(Staff $staff): array
    {
        $staff->loadMissing(['mda', 'currentEmployment.department', 'currentEmployment.station', 'currentEmployment.rank', 'currentSalaryPlacement.salaryScale']);

        return [
            'staff_id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'full_name' => $staff->full_name,
            'mda' => $staff->mda?->only(['id', 'code', 'name']),
            'department' => $staff->currentEmployment?->department?->only(['id', 'name']),
            'station' => $staff->currentEmployment?->station?->only(['id', 'name']),
            'rank' => $staff->currentEmployment?->rank?->only(['id', 'name', 'level']),
            'salary_scale' => $staff->currentSalaryPlacement?->salaryScale?->only(['id', 'code', 'name']),
            'level' => $staff->currentSalaryPlacement?->level,
            'step' => $staff->currentSalaryPlacement?->step,
            'date_last_promotion' => $staff->currentEmployment?->date_last_promotion?->toDateString(),
            'next_promotion_date' => $staff->currentEmployment?->next_promotion_date?->toDateString(),
        ];
    }

    protected function applyPromotionToStaff(PromotionApplication $application, User $actor): void
    {
        $staff = $application->staff;
        if (! $staff) {
            throw new InvalidArgumentException('This promotion application is not linked to a staff record.');
        }

        if (! $application->proposed_rank_id || ! $application->proposed_salary_scale_id || ! $application->proposed_level || ! $application->proposed_step) {
            throw new InvalidArgumentException('Proposed rank, salary scale, level, and step are required before printing.');
        }

        $effectiveDate = $application->sitting->sitting_date;
        $currentEmployment = $staff->currentEmployment;
        $currentPlacement = $staff->currentSalaryPlacement;
        $rate = SalaryStructureRate::query()
            ->where('mda_id', $application->mda_id)
            ->where('salary_scale_id', $application->proposed_salary_scale_id)
            ->where('level', $application->proposed_level)
            ->where('step', $application->proposed_step)
            ->first();

        if ($currentEmployment) {
            $currentEmployment->forceFill([
                'is_current' => false,
                'effective_to' => $effectiveDate,
            ])->save();
        }

        StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $application->mda_id,
            'department_id' => $currentEmployment?->department_id,
            'station_id' => $currentEmployment?->station_id,
            'location_name' => $currentEmployment?->location_name,
            'cadre_id' => $currentEmployment?->cadre_id,
            'rank_id' => $application->proposed_rank_id,
            'staff_category' => $currentEmployment?->staff_category,
            'initial_rank' => $currentEmployment?->initial_rank,
            'date_first_appointment' => $currentEmployment?->date_first_appointment,
            'date_last_promotion' => $effectiveDate,
            'expected_retirement_date' => $currentEmployment?->expected_retirement_date,
            'next_promotion_date' => null,
            'employment_status' => $currentEmployment?->employment_status ?? 'active',
            'is_current' => true,
            'effective_from' => $effectiveDate,
        ]);

        if ($currentPlacement) {
            $currentPlacement->forceFill([
                'is_current' => false,
                'effective_to' => $effectiveDate,
            ])->save();
        }

        StaffSalaryPlacement::query()->create([
            'staff_id' => $staff->id,
            'salary_scale_id' => $application->proposed_salary_scale_id,
            'level' => $application->proposed_level,
            'step' => $application->proposed_step,
            'basic_salary' => $rate?->basic_salary,
            'gross_salary' => $rate?->legacy_gross_salary ?? $rate?->basic_salary,
            'basic_salary_snapshot' => $rate?->basic_salary,
            'legacy_gross_salary_snapshot' => $rate?->legacy_gross_salary,
            'source' => 'promotion_letter',
            'is_current' => true,
            'effective_from' => $effectiveDate,
        ]);

        $staff->statusHistories()->create([
            'status' => 'active',
            'reason' => 'Promotion letter printed',
            'effective_from' => $effectiveDate,
            'metadata' => [
                'promotion_application_id' => $application->id,
                'acted_by' => $actor->id,
            ],
        ]);
    }

    protected function makeApplicationNumber(PromotionCycle $cycle, int $mdaId): string
    {
        return sprintf('PR-%s-%s-%06d', $cycle->year, $mdaId, PromotionApplication::query()->max('id') + 1);
    }

    protected function makeLetterNumber(PromotionApplication $application): string
    {
        return sprintf('PL-%s-%s', now()->format('Ymd'), Str::upper(Str::random(6)));
    }
}
