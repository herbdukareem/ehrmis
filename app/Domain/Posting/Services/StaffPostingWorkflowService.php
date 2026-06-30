<?php

namespace App\Domain\Posting\Services;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Station;
use App\Domain\Posting\Models\StaffPostingLetter;
use App\Domain\Posting\Models\StaffPostingRequest;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OfficialLetterPdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StaffPostingWorkflowService
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected OfficialLetterPdfService $letterPdfService,
    ) {
    }

    public function create(array $data, User $actor): StaffPostingRequest
    {
        return DB::transaction(function () use ($data, $actor): StaffPostingRequest {
            $staff = Staff::query()
                ->with(['mda', 'currentEmployment.department', 'currentEmployment.station', 'currentEmployment.rank'])
                ->lockForUpdate()
                ->findOrFail((int) $data['staff_id']);

            $currentEmployment = $staff->currentEmployment;
            if (! $currentEmployment) {
                throw new InvalidArgumentException('The selected staff member has no current employment record.');
            }

            $toMdaId = (int) $data['to_mda_id'];
            $toDepartmentId = $data['to_department_id'] ?? null;
            $toStationId = $data['to_station_id'] ?? null;
            $this->assertDestinationBelongsToMda($toMdaId, $toDepartmentId, $toStationId);

            $postingType = (int) $currentEmployment->mda_id === $toMdaId
                ? ((int) ($currentEmployment->station_id ?? 0) === (int) ($toStationId ?? 0) ? 'department_transfer' : 'station_transfer')
                : 'inter_mda_transfer';

            $request = StaffPostingRequest::query()->create([
                'staff_id' => $staff->id,
                'request_number' => $this->makeRequestNumber(),
                'posting_type' => $data['posting_type'] ?? $postingType,
                'from_mda_id' => $currentEmployment->mda_id,
                'from_department_id' => $currentEmployment->department_id,
                'from_station_id' => $currentEmployment->station_id,
                'to_mda_id' => $toMdaId,
                'to_department_id' => $toDepartmentId,
                'to_station_id' => $toStationId,
                'effective_date' => $data['effective_date'],
                'reason' => $data['reason'] ?? null,
                'staff_snapshot' => $this->staffSnapshot($staff),
                'status' => 'draft',
                'requested_by' => $actor->id,
            ]);

            $this->auditLogService->logCreated($request, ['source' => 'posting_request.create']);

            return $request->fresh($this->relations());
        });
    }

    public function submit(StaffPostingRequest $request, User $actor): StaffPostingRequest
    {
        if (! in_array($request->status, ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException('Only draft or rejected posting requests can be submitted.');
        }

        return $this->transition($request, [
            'status' => 'submitted',
            'submitted_at' => now(),
        ], 'posting_request.submitted', $actor, 'submission', 'submitted');
    }

    public function approveOrigin(StaffPostingRequest $request, User $actor, ?string $comment = null): StaffPostingRequest
    {
        if ($request->status !== 'submitted') {
            throw new InvalidArgumentException('Only submitted posting requests can receive origin approval.');
        }

        $nextStatus = (int) $request->from_mda_id === (int) $request->to_mda_id ? 'approved' : 'from_mda_approved';

        return $this->transition($request, ['status' => $nextStatus], 'posting_request.origin_approved', $actor, 'origin_mda', 'approved', $comment);
    }

    public function approveReceiving(StaffPostingRequest $request, User $actor, ?string $comment = null): StaffPostingRequest
    {
        if ($request->status !== 'from_mda_approved') {
            throw new InvalidArgumentException('Only origin-approved inter-MDA postings can receive receiving-MDA approval.');
        }

        if ((int) $request->from_mda_id === (int) $request->to_mda_id) {
            throw new InvalidArgumentException('Same-MDA postings do not need receiving-MDA approval.');
        }

        return $this->transition($request, ['status' => 'receiving_mda_approved'], 'posting_request.receiving_approved', $actor, 'receiving_mda', 'approved', $comment);
    }

    public function approveFinal(StaffPostingRequest $request, User $actor, ?string $comment = null): StaffPostingRequest
    {
        if (! in_array($request->status, ['from_mda_approved', 'receiving_mda_approved'], true)) {
            throw new InvalidArgumentException('This posting request is not ready for final approval.');
        }

        return $this->transition($request, ['status' => 'approved'], 'posting_request.final_approved', $actor, 'final', 'approved', $comment);
    }

    public function reject(StaffPostingRequest $request, User $actor, string $comment): StaffPostingRequest
    {
        if (in_array($request->status, ['effected', 'cancelled'], true)) {
            throw new InvalidArgumentException('Effected or cancelled postings cannot be rejected.');
        }

        return $this->transition($request, ['status' => 'rejected'], 'posting_request.rejected', $actor, 'review', 'rejected', $comment);
    }

    public function issueLetter(StaffPostingRequest $request, User $actor): StaffPostingLetter
    {
        if ($request->status !== 'approved') {
            throw new InvalidArgumentException('Only approved postings can have letters issued.');
        }

        return DB::transaction(function () use ($request, $actor): StaffPostingLetter {
            $request = StaffPostingRequest::query()->lockForUpdate()->findOrFail($request->id);

            $letter = StaffPostingLetter::query()->firstOrCreate(
                ['posting_request_id' => $request->id],
                [
                    'letter_number' => $this->makeLetterNumber(),
                    'status' => 'generated',
                    'generated_by' => $actor->id,
                    'generated_at' => now(),
                ],
            );

            $before = $request->toArray();
            $letter = $this->letterPdfService->renderPostingLetter($letter);

            $request->forceFill([
                'status' => 'issued',
                'issued_by' => $actor->id,
                'issued_at' => now(),
            ])->save();

            $letter->forceFill([
                'status' => 'printed',
                'printed_by' => $actor->id,
                'printed_at' => now(),
            ])->save();

            $this->auditLogService->logUpdated($request, $before, ['source' => 'posting_letter.issued']);

            return $letter->fresh(['request.staff']);
        });
    }

    public function effect(StaffPostingRequest $request, User $actor): StaffPostingRequest
    {
        if (! in_array($request->status, ['approved', 'issued'], true)) {
            throw new InvalidArgumentException('Only approved or issued posting requests can be effected.');
        }

        return DB::transaction(function () use ($request, $actor): StaffPostingRequest {
            $request = StaffPostingRequest::query()->with('staff.currentEmployment')->lockForUpdate()->findOrFail($request->id);
            $staff = Staff::query()->with('currentEmployment')->lockForUpdate()->findOrFail($request->staff_id);
            $currentEmployment = $staff->currentEmployment;

            if (! $currentEmployment) {
                throw new InvalidArgumentException('The selected staff member has no current employment record.');
            }

            $before = [
                'request' => $request->toArray(),
                'staff' => $staff->toArray(),
                'current_employment' => $currentEmployment->toArray(),
            ];

            $currentEmployment->forceFill([
                'is_current' => false,
                'effective_to' => $request->effective_date,
            ])->save();

            StaffEmployment::query()->create([
                'staff_id' => $staff->id,
                'mda_id' => $request->to_mda_id,
                'department_id' => $request->to_department_id,
                'station_id' => $request->to_station_id,
                'location_name' => $currentEmployment->location_name,
                'cadre_id' => $currentEmployment->cadre_id,
                'rank_id' => $currentEmployment->rank_id,
                'staff_category' => $currentEmployment->staff_category,
                'initial_rank' => $currentEmployment->initial_rank,
                'date_first_appointment' => $currentEmployment->date_first_appointment,
                'date_last_promotion' => $currentEmployment->date_last_promotion,
                'expected_retirement_date' => $currentEmployment->expected_retirement_date,
                'next_promotion_date' => $currentEmployment->next_promotion_date,
                'employment_status' => $currentEmployment->employment_status,
                'is_current' => true,
                'effective_from' => $request->effective_date,
            ]);

            $staff->forceFill(['mda_id' => $request->to_mda_id])->save();
            $staff->statusHistories()->create([
                'status' => $staff->status,
                'reason' => 'Staff posting effected',
                'effective_from' => $request->effective_date,
                'metadata' => [
                    'posting_request_id' => $request->id,
                    'from_mda_id' => $request->from_mda_id,
                    'to_mda_id' => $request->to_mda_id,
                    'acted_by' => $actor->id,
                ],
            ]);

            $request->forceFill([
                'status' => 'effected',
                'effected_by' => $actor->id,
                'effected_at' => now(),
            ])->save();

            $this->auditLogService->log('posting_request.effected', $request, $before, [
                'request' => $request->fresh()->toArray(),
                'staff' => $staff->fresh()->toArray(),
            ]);

            return $request->fresh($this->relations());
        });
    }

    protected function transition(
        StaffPostingRequest $request,
        array $fields,
        string $event,
        User $actor,
        string $stage,
        string $decision,
        ?string $comment = null,
    ): StaffPostingRequest {
        return DB::transaction(function () use ($request, $fields, $event, $actor, $stage, $decision, $comment): StaffPostingRequest {
            $request = StaffPostingRequest::query()->lockForUpdate()->findOrFail($request->id);
            $before = $request->load('approvals')->toArray();

            $request->forceFill($fields)->save();
            $request->approvals()->create([
                'stage' => $stage,
                'decision' => $decision,
                'comment' => $comment,
                'acted_by' => $actor->id,
                'acted_at' => now(),
            ]);

            $this->auditLogService->log($event, $request, $before, $request->fresh('approvals')->toArray());

            return $request->fresh($this->relations());
        });
    }

    protected function assertDestinationBelongsToMda(int $mdaId, mixed $departmentId, mixed $stationId): void
    {
        if ($departmentId && ! Department::query()->whereKey((int) $departmentId)->where('mda_id', $mdaId)->exists()) {
            throw new InvalidArgumentException('The destination department must belong to the destination MDA.');
        }

        if ($stationId && ! Station::query()->whereKey((int) $stationId)->where('mda_id', $mdaId)->exists()) {
            throw new InvalidArgumentException('The destination station must belong to the destination MDA.');
        }
    }

    protected function staffSnapshot(Staff $staff): array
    {
        $employment = $staff->currentEmployment;

        return [
            'staff_id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'full_name' => $staff->full_name,
            'mda' => $staff->mda?->only(['id', 'code', 'name']),
            'department' => $employment?->department?->only(['id', 'name']),
            'station' => $employment?->station?->only(['id', 'name']),
            'rank' => $employment?->rank?->only(['id', 'name', 'level']),
        ];
    }

    protected function makeRequestNumber(): string
    {
        return sprintf('PO-%s-%s', now()->format('Ymd'), Str::upper(Str::random(6)));
    }

    protected function makeLetterNumber(): string
    {
        return sprintf('PT-%s-%s', now()->format('Ymd'), Str::upper(Str::random(6)));
    }

    protected function relations(): array
    {
        return [
            'staff',
            'fromMda',
            'toMda',
            'fromDepartment',
            'toDepartment',
            'fromStation',
            'toStation',
            'approvals.actor',
            'letter',
        ];
    }
}
