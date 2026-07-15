<?php

namespace App\Domain\Posting\Services;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\MdaSetting;
use App\Domain\Organization\Models\Station;
use App\Domain\Posting\Models\StaffPostingLetter;
use App\Domain\Posting\Models\StaffPostingRequest;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OfficialLetterPdfService;
use Illuminate\Support\Collection;
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
            $staffIds = $this->normalizeStaffIds($data);
            $staffMembers = Staff::query()
                ->with(['mda', 'currentEmployment.department', 'currentEmployment.station', 'currentEmployment.rank'])
                ->lockForUpdate()
                ->whereIn('id', $staffIds)
                ->get()
                ->sortBy(fn (Staff $staff) => array_search($staff->id, $staffIds, true))
                ->values();

            if ($staffMembers->count() !== count($staffIds)) {
                throw new InvalidArgumentException('One or more selected staff records could not be found.');
            }

            $firstStaff = $staffMembers->first();
            $originMdaId = (int) $firstStaff->currentEmployment?->mda_id;

            foreach ($staffMembers as $staff) {
                if (! $staff->currentEmployment) {
                    throw new InvalidArgumentException("{$staff->full_name} has no current employment record.");
                }

                if ((int) $staff->currentEmployment->mda_id !== $originMdaId) {
                    throw new InvalidArgumentException('Grouped posting requests can only include staff from the same origin MDA.');
                }
            }

            $toMdaId = (int) $data['to_mda_id'];
            $toDepartmentId = $data['to_department_id'] ?? null;
            $toStationId = $data['to_station_id'] ?? null;
            $this->assertDestinationBelongsToMda($toMdaId, $toDepartmentId, $toStationId);

            $postingType = $this->resolvePostingType($staffMembers, $toMdaId, $toStationId);
            $commonDepartmentId = $this->commonOriginValue($staffMembers, 'department_id');
            $commonStationId = $this->commonOriginValue($staffMembers, 'station_id');

            $request = StaffPostingRequest::query()->create([
                'staff_id' => $firstStaff->id,
                'request_number' => $this->makeRequestNumber(),
                'posting_type' => $postingType,
                'from_mda_id' => $originMdaId,
                'from_department_id' => $commonDepartmentId,
                'from_station_id' => $commonStationId,
                'to_mda_id' => $toMdaId,
                'to_department_id' => $toDepartmentId,
                'to_station_id' => $toStationId,
                'effective_date' => $data['effective_date'],
                'reason' => $data['reason'] ?? null,
                'staff_snapshot' => $this->staffSummarySnapshot($staffMembers),
                'status' => 'draft',
                'requested_by' => $actor->id,
            ]);

            $request->items()->createMany($staffMembers->map(fn (Staff $staff): array => [
                'staff_id' => $staff->id,
                'staff_snapshot' => $this->staffSnapshot($staff),
            ])->all());

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

    public function revertToPreviousStage(StaffPostingRequest $request, User $actor, ?string $comment = null): StaffPostingRequest
    {
        return DB::transaction(function () use ($request, $actor, $comment): StaffPostingRequest {
            $request = StaffPostingRequest::query()
                ->with(['approvals', 'letter'])
                ->lockForUpdate()
                ->findOrFail($request->id);

            [$fields, $stage] = $this->revertDefinition($request);
            $before = $request->toArray();

            if ($request->status === 'issued' && $request->letter) {
                $request->letter->forceFill([
                    'status' => 'revoked',
                    'pdf_path' => null,
                    'printed_by' => null,
                    'printed_at' => null,
                ])->save();
            }

            $request->forceFill($fields)->save();
            $request->approvals()->create([
                'stage' => $stage,
                'decision' => 'reverted',
                'comment' => $comment,
                'acted_by' => $actor->id,
                'acted_at' => now(),
            ]);

            $this->auditLogService->log('posting_request.reverted', $request, $before, $request->fresh(['approvals', 'letter'])->toArray());

            return $request->fresh($this->relations());
        });
    }

    public function issueLetter(StaffPostingRequest $request, User $actor, array $attributes = []): StaffPostingLetter
    {
        if (! in_array($request->status, ['approved', 'issued', 'effected'], true)) {
            throw new InvalidArgumentException('Only approved or completed postings can have letters issued.');
        }

        return DB::transaction(function () use ($request, $actor, $attributes): StaffPostingLetter {
            $request = StaffPostingRequest::query()
                ->with([
                    'items',
                    'fromMda.setting.headStaff',
                    'fromMda.setting.headRank',
                    'toMda',
                    'toDepartment',
                    'toStation',
                ])
                ->lockForUpdate()
                ->findOrFail($request->id);

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
            $letterData = $this->finalizeLetterData($request, $letter, $attributes);

            if ($letter->status === 'revoked') {
                $letter->forceFill([
                    'status' => 'generated',
                    'generated_by' => $actor->id,
                    'generated_at' => now(),
                    'printed_by' => null,
                    'printed_at' => null,
                    'pdf_path' => null,
                ])->save();
            }

            $letter->forceFill(array_merge($letterData, [
                'status' => 'generated',
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ]))->save();

            $letter = $this->letterPdfService->renderPostingLetter($letter);

            $requestUpdates = [
                'issued_by' => $request->issued_by ?: $actor->id,
                'issued_at' => $request->issued_at ?: now(),
            ];

            if ($request->status === 'approved') {
                $requestUpdates['status'] = 'issued';
            }

            $request->forceFill($requestUpdates)->save();

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
            $request = StaffPostingRequest::query()->with(['items', 'staff.currentEmployment'])->lockForUpdate()->findOrFail($request->id);
            $staffIds = $request->items->pluck('staff_id')->filter()->values()->all();
            if ($staffIds === []) {
                $staffIds = [$request->staff_id];
            }

            $staffMembers = Staff::query()
                ->with('currentEmployment')
                ->lockForUpdate()
                ->whereIn('id', $staffIds)
                ->get()
                ->keyBy('id');

            $before = [
                'request' => $request->toArray(),
                'staff' => $staffMembers->map(fn (Staff $staff) => $staff->toArray())->values()->all(),
            ];

            foreach ($staffIds as $staffId) {
                $staff = $staffMembers->get($staffId);
                $currentEmployment = $staff?->currentEmployment;

                if (! $staff || ! $currentEmployment) {
                    throw new InvalidArgumentException('One or more selected staff members no longer have a current employment record.');
                }

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
            }

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

    protected function revertDefinition(StaffPostingRequest $request): array
    {
        return match ($request->status) {
            'submitted' => [
                ['status' => 'draft', 'submitted_at' => null],
                'submission',
            ],
            'from_mda_approved' => [
                ['status' => 'submitted'],
                'origin_mda',
            ],
            'receiving_mda_approved' => [
                ['status' => 'from_mda_approved'],
                'receiving_mda',
            ],
            'approved' => (int) $request->from_mda_id === (int) $request->to_mda_id
                ? [
                    ['status' => 'submitted'],
                    'origin_mda',
                ]
                : [
                    ['status' => 'receiving_mda_approved'],
                    'final',
                ],
            'issued' => [
                ['status' => 'approved', 'issued_by' => null, 'issued_at' => null],
                'letter',
            ],
            default => throw new InvalidArgumentException('This posting request cannot return to a previous stage.'),
        };
    }

    public function letterDraft(StaffPostingRequest $request): array
    {
        $request->loadMissing([
            'letter',
            'fromMda.setting.headStaff.currentEmployment.rank',
            'fromMda.setting.headRank',
            'toMda',
            'toDepartment',
            'toStation',
            'items',
            'staff',
        ]);

        return $this->defaultLetterData($request, $request->letter);
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

    protected function staffSummarySnapshot(Collection $staffMembers): array
    {
        $primary = $staffMembers->first();

        return [
            'count' => $staffMembers->count(),
            'staff_ids' => $staffMembers->pluck('id')->values()->all(),
            'primary_staff_id' => $primary?->id,
            'primary_staff_number' => $primary?->staff_number,
            'primary_staff_name' => $primary?->full_name,
        ];
    }

    protected function normalizeStaffIds(array $data): array
    {
        $staffIds = collect($data['staff_ids'] ?? [$data['staff_id'] ?? null])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        if ($staffIds === []) {
            throw new InvalidArgumentException('Select at least one staff member for this posting request.');
        }

        return $staffIds;
    }

    protected function resolvePostingType(Collection $staffMembers, int $toMdaId, mixed $toStationId): string
    {
        $originMdaId = (int) $staffMembers->first()->currentEmployment->mda_id;
        if ($originMdaId !== $toMdaId) {
            return 'inter_mda_transfer';
        }

        $allSameStation = $staffMembers->every(fn (Staff $staff) => (int) ($staff->currentEmployment?->station_id ?? 0) === (int) ($toStationId ?? 0));

        return $allSameStation ? 'department_transfer' : 'station_transfer';
    }

    protected function commonOriginValue(Collection $staffMembers, string $field): ?int
    {
        $values = $staffMembers
            ->map(fn (Staff $staff) => $staff->currentEmployment?->{$field})
            ->unique()
            ->values();

        if ($values->count() !== 1) {
            return null;
        }

        return $values->first() ? (int) $values->first() : null;
    }

    protected function defaultLetterData(StaffPostingRequest $request, ?StaffPostingLetter $letter = null): array
    {
        $letter ??= $request->letter;
        $setting = $request->fromMda?->setting;
        $subject = $this->defaultSubjectLine($request);

        return [
            'official_reference' => $letter?->official_reference ?? $this->previewOfficialReference($request),
            'subject_line' => $letter?->subject_line ?? $subject,
            'recipient_name' => $letter?->recipient_name ?? '',
            'recipient_organisation' => $letter?->recipient_organisation ?? ($request->toStation?->name ?? $request->toMda?->name ?? ''),
            'recipient_location' => $letter?->recipient_location ?? '',
            'attention_line' => $letter?->attention_line ?? $this->defaultAttentionLine($request),
            'signatory_name' => $letter?->signatory_name ?? ($setting?->headStaff?->full_name ?? $setting?->headRank?->name ?? ''),
            'signatory_title' => $letter?->signatory_title ?? ($setting?->head_title ?? ''),
            'signatory_for_line' => $letter?->signatory_for_line ?? '',
        ];
    }

    protected function finalizeLetterData(StaffPostingRequest $request, StaffPostingLetter $letter, array $attributes): array
    {
        $defaults = $this->defaultLetterData($request, $letter);
        $officialReference = $letter->official_reference;
        $referenceSequence = $letter->reference_sequence;

        if (! $officialReference || ! $referenceSequence) {
            ['reference' => $officialReference, 'sequence' => $referenceSequence] = $this->reserveOfficialReference($request);
        }

        $data = [
            'official_reference' => $officialReference,
            'reference_sequence' => $referenceSequence,
            'subject_line' => trim((string) ($attributes['subject_line'] ?? $defaults['subject_line'] ?? '')),
            'recipient_name' => trim((string) ($attributes['recipient_name'] ?? $defaults['recipient_name'] ?? '')),
            'recipient_organisation' => trim((string) ($attributes['recipient_organisation'] ?? $defaults['recipient_organisation'] ?? '')),
            'recipient_location' => trim((string) ($attributes['recipient_location'] ?? $defaults['recipient_location'] ?? '')),
            'attention_line' => trim((string) ($attributes['attention_line'] ?? $defaults['attention_line'] ?? '')),
            'signatory_name' => trim((string) ($attributes['signatory_name'] ?? $defaults['signatory_name'] ?? '')),
            'signatory_title' => trim((string) ($attributes['signatory_title'] ?? $defaults['signatory_title'] ?? '')),
            'signatory_for_line' => trim((string) ($attributes['signatory_for_line'] ?? $defaults['signatory_for_line'] ?? '')),
        ];

        foreach (['subject_line', 'recipient_name', 'recipient_organisation', 'signatory_name', 'signatory_title'] as $requiredField) {
            if ($data[$requiredField] === '') {
                throw new InvalidArgumentException('Complete all required posting letter details before issuing the letter.');
            }
        }

        return $data;
    }

    protected function previewOfficialReference(StaffPostingRequest $request): string
    {
        $setting = $request->fromMda?->setting;
        $sequence = StaffPostingLetter::query()
            ->join('staff_posting_requests', 'staff_posting_requests.id', '=', 'staff_posting_letters.posting_request_id')
            ->where('staff_posting_requests.from_mda_id', $request->from_mda_id)
            ->max('staff_posting_letters.reference_sequence');

        return $this->formatOfficialReference(
            $request,
            (int) ($sequence ?? 0) + 1,
            $setting,
        );
    }

    protected function reserveOfficialReference(StaffPostingRequest $request): array
    {
        $setting = $request->fromMda?->setting ?? MdaSetting::query()->where('mda_id', $request->from_mda_id)->first();
        $sequence = (int) (StaffPostingLetter::query()
            ->join('staff_posting_requests', 'staff_posting_requests.id', '=', 'staff_posting_letters.posting_request_id')
            ->where('staff_posting_requests.from_mda_id', $request->from_mda_id)
            ->lockForUpdate()
            ->max('staff_posting_letters.reference_sequence') ?? 0) + 1;

        return [
            'sequence' => $sequence,
            'reference' => $this->formatOfficialReference($request, $sequence, $setting),
        ];
    }

    protected function formatOfficialReference(StaffPostingRequest $request, int $sequence, ?MdaSetting $setting): string
    {
        $prefix = trim((string) ($setting?->posting_reference_prefix ?: (($request->fromMda?->code ?? 'MDA').'/STA')), '/');
        $suffix = trim((string) ($setting?->posting_reference_suffix ?: 'VOL.1'), '/');

        return $suffix !== ''
            ? sprintf('%s/%d/%s', $prefix, $sequence, $suffix)
            : sprintf('%s/%d', $prefix, $sequence);
    }

    protected function defaultSubjectLine(StaffPostingRequest $request): string
    {
        if ($request->toDepartment?->name) {
            return 'POSTING OF '.Str::upper($request->toDepartment->name).' STAFF';
        }

        return 'POSTING OF STAFF';
    }

    protected function defaultAttentionLine(StaffPostingRequest $request): string
    {
        if ($request->toDepartment?->name) {
            return 'HOD '.Str::upper($request->toDepartment->name).'.';
        }

        if ($request->toStation?->name) {
            return 'Officer In Charge, '.Str::upper($request->toStation->name).'.';
        }

        return '';
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
            'items.staff',
            'approvals.actor',
            'letter',
        ];
    }
}
