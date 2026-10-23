<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Models\OfficeDelegation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OfficeContinuityService
{
    public const DELEGATABLE = ['read', 'create', 'edit', 'schedule', 'register', 'meetings', 'route', 'decide', 'review', 'respond', 'dispatch', 'notes', 'brief', 'report', 'archive'];

    public function __construct(protected OfficeAccess $access, protected OfficeAdministrationService $administration, protected OfficeRecordService $records) {}

    public function delegate(User $actor, Office $office, array $input): OfficeDelegation
    {
        $this->access->require($actor, $office, 'delegate');
        $data = Validator::make($input, ['user_id' => ['required', 'integer'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'abilities' => ['required', 'array', 'min:1'], 'abilities.*' => ['required', 'distinct', Rule::in(self::DELEGATABLE)],
            'executive_only_access' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:5000']])->validate();
        $this->records->validatePeople($office, ['assigned_to' => $data['user_id']]);
        abort_if((int) $actor->id === (int) $data['user_id'], 422, 'Choose another user.');
        $data['abilities'] = array_values(array_unique(['read', ...$data['abilities']]));
        $data['starts_at'] = Carbon::parse($data['starts_at'], $office->timezone)->utc();
        $data['ends_at'] = Carbon::parse($data['ends_at'], $office->timezone)->utc();

        return DB::transaction(function () use ($actor, $office, $data) {
            Office::query()->whereKey($office->id)->where('mda_id', $office->mda_id)->lockForUpdate()->firstOrFail();
            $this->access->require($actor, $office, 'delegate');
            $grant = $office->delegations()->create($data + ['mda_id' => $office->mda_id, 'granted_by' => $actor->id]);
            User::query()->findOrFail($data['user_id'])->givePermissionTo('view-executive-office');
            $this->administration->event($office, $actor, 'delegation_granted', ['delegation_id' => $grant->id, 'user_id' => $grant->user_id, 'abilities' => $grant->abilities, 'starts_at' => $grant->starts_at, 'ends_at' => $grant->ends_at]);

            return $grant;
        });
    }

    public function revoke(User $actor, Office $office, int $id): void
    {
        $this->access->require($actor, $office, 'delegate');
        $grant = $office->delegations()->where('mda_id', $office->mda_id)->findOrFail($id);
        $grant->update(['revoked_at' => now()]);
        $this->administration->event($office, $actor, 'delegation_revoked', ['delegation_id' => $grant->id]);
    }

    public function handover(User $actor, Office $office, array $input): array
    {
        $this->access->assertEnabled($actor, $office);
        abort_unless($this->access->configure($actor, $office->mda_id), 403);
        $data = Validator::make($input, ['outgoing_user_id' => ['required', 'integer'], 'incoming_user_id' => ['required', 'integer', 'different:outgoing_user_id'], 'reason' => ['required', 'string', 'max:5000']])->validate();
        $this->records->validatePeople($office, ['assigned_to' => $data['incoming_user_id']]);

        return DB::transaction(function () use ($actor, $office, $data) {
            Office::query()->whereKey($office->id)->where('mda_id', $office->mda_id)->lockForUpdate()->firstOrFail();
            $memberships = $office->memberships()->active()->where('user_id', $data['outgoing_user_id'])->get();
            abort_if($memberships->isEmpty(), 422, 'The outgoing user has no current office assignment.');
            $office->memberships()->where('user_id', $data['outgoing_user_id'])->whereNull('revoked_at')
                ->when((int) $actor->id === (int) $data['outgoing_user_id'], fn ($q) => $q->where('role', '!=', 'administrator'))
                ->update(['ends_at' => now(), 'revoked_at' => now()]);
            $office->delegations()->where('user_id', $data['outgoing_user_id'])->whereNull('revoked_at')->update(['revoked_at' => now()]);
            foreach ($memberships->pluck('role')->unique() as $role) {
                if (! $office->memberships()->active()->where('user_id', $data['incoming_user_id'])->where('role', $role)->exists()) {
                    $this->administration->assign($actor, $office, ['user_id' => $data['incoming_user_id'], 'role' => $role, 'starts_at' => now()->toIso8601String()]);
                }
            }
            $office->memberships()->where('user_id', $data['outgoing_user_id'])->whereNull('revoked_at')->update(['ends_at' => now(), 'revoked_at' => now()]);
            $count = 0;
            $office->records()->where('mda_id', $office->mda_id)->whereNull('closed_at')->whereNull('archived_at')->orderBy('id')->chunkById(100, function ($rows) use ($office, $actor, $data, &$count) {
                foreach ($rows as $record) {
                    $changed = false;
                    if ((int) $record->assigned_to === (int) $data['outgoing_user_id']) {
                        $record->assigned_to = $data['incoming_user_id'];
                        $changed = true;
                    }
                    $participants = $record->details['participant_user_ids'] ?? [];
                    if ($record->starts_at?->isFuture() && in_array((int) $data['outgoing_user_id'], $participants, true)) {
                        $record->details = array_replace($record->details, ['participant_user_ids' => array_values(array_unique(array_map(fn ($id) => (int) $id === (int) $data['outgoing_user_id'] ? (int) $data['incoming_user_id'] : $id, $participants)))]);
                        $changed = true;
                    }
                    if ($changed) {
                        $record->version++;
                        $record->save();
                        $count++;
                        $this->records->event($actor, $office, $record, 'responsibility_transferred', $data['reason']);
                    }
                }
            });
            $this->administration->event($office, $actor, 'handover_completed', $data + ['transferred_records' => $count]);

            return ['transferred_records' => $count];
        });
    }

    public function prepareHandover(User $actor, Office $office)
    {
        $this->access->require($actor, $office, 'brief');
        $rows = $this->access->visible($actor, $office)->whereNull('archived_at')->whereNull('closed_at')->orderBy('due_at')->get();
        $classification = $rows->contains('confidentiality', 'executive_only') ? 'executive_only' : 'office_restricted';
        $summary = $rows->map(fn ($row) => $row->reference.' — '.$row->subject.' — '.$row->status.' — Deadline: '.($row->due_at?->copy()->timezone($office->timezone)->format('d-m-Y H:i') ?? 'Not set'))->implode("\n");

        return $this->records->create($actor, $office, ['type' => 'briefing', 'subject' => 'Executive office handover pack', 'priority' => 'high', 'confidentiality' => $classification,
            'details' => ['briefing_date' => now($office->timezone)->toDateString(), 'summary' => $summary ?: 'No outstanding matters.']]);
    }
}
