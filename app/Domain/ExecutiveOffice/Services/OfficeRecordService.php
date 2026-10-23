<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Models\OfficeAttachment;
use App\Domain\ExecutiveOffice\Models\OfficeEvent;
use App\Domain\ExecutiveOffice\Models\OfficeLink;
use App\Domain\ExecutiveOffice\Models\OfficeRecord;
use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Domain\Organization\Models\Department;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfficeRecordService
{
    public function __construct(public OfficeAccess $access, protected AuditLogService $audit) {}

    public function data(array $input, string $type): array
    {
        $rules = [
            'subject' => ['required', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:100000'],
            'priority' => ['required', Rule::in(['normal', 'high', 'urgent'])], 'confidentiality' => ['required', Rule::in(OfficeCatalog::CLASSIFICATIONS)],
            'assigned_to' => [$type === 'directive' ? 'required' : 'nullable', 'integer', 'min:1'], 'department_id' => ['nullable', 'integer', 'min:1'],
            'starts_at' => [in_array($type, ['appointment', 'meeting']) || ($type === 'visitor' && ! ($input['details']['walk_in'] ?? false)) ? 'required' : 'nullable', 'date'],
            'ends_at' => [in_array($type, ['appointment', 'meeting']) ? 'required' : 'nullable', 'date', 'after:starts_at'],
            'due_at' => [$type === 'directive' ? 'required' : 'nullable', 'date'], 'details' => ['required', 'array'],
        ];
        $fields = match ($type) {
            'correspondence' => ['direction' => ['required', Rule::in(['incoming', 'outgoing'])], 'sender' => ['required', 'string', 'max:255'], 'recipient' => ['required_if:details.direction,outgoing', 'nullable', 'string', 'max:255'], 'document_date' => ['nullable', 'date'], 'received_at' => ['nullable', 'date'], 'external_reference' => ['nullable', 'string', 'max:255'], 'origin' => ['nullable', 'string', 'max:255']],
            'appointment' => ['requester' => ['required', 'string', 'max:255'], 'venue' => ['required', 'string', 'max:255'], 'sensitive' => ['required', 'boolean'], 'participant_user_ids' => ['sometimes', 'array', 'max:100'], 'external_participants' => ['nullable', 'string', 'max:10000']],
            'directive' => ['instruction' => ['required', 'string', 'max:50000']],
            'meeting' => ['venue' => ['required', 'string', 'max:255'], 'agenda' => ['required', 'string', 'max:50000'], 'participant_user_ids' => ['sometimes', 'array', 'max:100'], 'external_participants' => ['nullable', 'string', 'max:10000'], 'minutes' => ['nullable', 'string', 'max:100000']],
            'visitor' => ['visitor_name' => ['required', 'string', 'max:255'], 'organisation' => ['nullable', 'string', 'max:255'], 'contact' => ['nullable', 'string', 'max:255'], 'purpose' => ['required', 'string', 'max:5000'], 'host' => ['required', 'string', 'max:255'], 'walk_in' => ['required', 'boolean']],
            'briefing' => ['briefing_date' => ['required', 'date'], 'summary' => ['required', 'string', 'max:100000']],
        };
        foreach ($fields as $key => $rule) {
            $rules['details.'.$key] = $rule;
        }
        $rules['details.participant_user_ids.*'] = ['integer', 'min:1', 'distinct'];
        $data = Validator::make($input, $rules)->validate();
        $data['details'] = array_intersect_key($data['details'], $fields);
        if (isset($data['details']['participant_user_ids'])) {
            $data['details']['participant_user_ids'] = array_map('intval', $data['details']['participant_user_ids']);
        }

        return $data;
    }

    public function validatePeople(Office $office, array $data): void
    {
        foreach (array_filter([$data['assigned_to'] ?? null, ...($data['details']['participant_user_ids'] ?? [])]) as $id) {
            $user = User::query()->find($id);
            if (! $user || ! $user->canAccessMda($office->mda_id) || $user->status?->value !== 'active') {
                throw ValidationException::withMessages(['assigned_to' => 'Assignees and participants must be active users with access to this MDA.']);
            }
        }
        if (! empty($data['department_id']) && ! Department::query()->where('mda_id', $office->mda_id)->whereKey($data['department_id'])->exists()) {
            throw ValidationException::withMessages(['department_id' => 'Choose a department in this MDA.']);
        }
    }

    public function grantRecipientAccess(User $actor, Office $office, OfficeRecord $record): void
    {
        if ($record->type === 'directive' && in_array($record->status, ['draft', 'awaiting_decision'], true)) {
            return;
        }
        foreach (array_unique(array_filter([$record->assigned_to, ...($record->details['participant_user_ids'] ?? [])])) as $id) {
            if ($office->memberships()->active()->where('user_id', $id)->exists()) {
                continue;
            }
            $office->memberships()->create(['mda_id' => $office->mda_id, 'user_id' => $id, 'role' => 'action_officer', 'starts_at' => now(), 'assigned_by' => $actor->id]);
            User::query()->findOrFail($id)->givePermissionTo('view-executive-office');
        }
    }

    public function officeDates(Office $office, array $data): array
    {
        foreach (['starts_at', 'ends_at', 'due_at'] as $key) {
            if (! empty($data[$key])) {
                $data[$key] = Carbon::parse($data[$key], $office->timezone)->utc();
            }
        }

        return $data;
    }

    public function create(User $actor, Office $office, array $input): OfficeRecord
    {
        $type = Validator::make($input, ['type' => ['required', Rule::in(OfficeCatalog::TYPES)]])->validate()['type'];
        $ability = match ($type) {
            'visitor', 'correspondence' => 'register', 'appointment' => 'schedule', 'meeting' => 'meetings', 'briefing' => 'brief', default => 'create'
        };
        $this->access->require($actor, $office, $ability);
        $data = $this->officeDates($office, $this->data($input, $type));
        if ($type === 'visitor' && empty($data['starts_at'])) {
            $data['starts_at'] = now();
        }
        $this->validatePeople($office, $data);
        $this->assertClassification($actor, $office, $data['confidentiality']);
        if (! $this->access->team($actor, $office)) {
            abort_unless($data['confidentiality'] === 'standard' && in_array($type, ['visitor', 'correspondence'], true), 403);
            abort_if($type === 'correspondence' && $data['details']['direction'] !== 'incoming', 403);
            $data['assigned_to'] = null;
            $data['department_id'] = null;
        }

        return DB::transaction(function () use ($actor, $office, $data, $type, $ability) {
            $locked = Office::query()->whereKey($office->id)->where('mda_id', $office->mda_id)->lockForUpdate()->firstOrFail();
            $this->access->require($actor, $locked, $ability);
            $reference = $locked->reference_prefix.'/'.now()->format('Y').'/'.str_pad((string) $locked->next_reference, 6, '0', STR_PAD_LEFT);
            $locked->increment('next_reference');
            $status = match ($type) {
                'correspondence' => $data['details']['direction'] === 'incoming' ? 'received' : 'draft',
                'appointment' => 'requested', 'visitor' => 'expected', default => 'draft',
            };
            $record = $office->records()->create($data + ['mda_id' => $office->mda_id, 'type' => $type, 'reference' => $reference, 'created_by' => $actor->id, 'status' => $status]);
            $this->grantRecipientAccess($actor, $office, $record);
            $this->event($actor, $office, $record, 'registered');

            return $record;
        });
    }

    public function assertClassification(User $actor, Office $office, string $classification): void
    {
        if ($classification === 'executive_only') {
            abort_unless($this->access->executiveContent($actor, $office), 403);
        }
    }

    public function update(User $actor, Office $office, OfficeRecord $record, array $input): OfficeRecord
    {
        $this->access->require($actor, $office, 'edit');

        return DB::transaction(function () use ($actor, $office, $record, $input) {
            Office::query()->whereKey($office->id)->lockForUpdate()->firstOrFail();
            $version = Validator::make($input, ['version' => ['required', 'integer', 'min:1']])->validate()['version'];
            $record = $this->lockedRecord($actor, $office, $record->id, $version);
            abort_if($record->archived_at || in_array($record->status, OfficeCatalog::CLOSED, true), 422, 'Reopen this record before editing.');
            $data = $this->officeDates($office, $this->data($input, $record->type));
            $this->validatePeople($office, $data);
            $this->assertClassification($actor, $office, $data['confidentiality']);
            if (isset($record->details['direction'])) {
                $data['details']['direction'] = $record->details['direction'];
            }
            $data['details'] = array_replace($record->details ?? [], $data['details']);
            $record->fill($data);
            if ($record->status === 'confirmed') {
                $record->status = $record->type === 'appointment' ? 'requested' : 'draft';
            }
            if ($record->type === 'directive') {
                $record->status = 'draft';
            }
            $record->version++;
            $record->approved_version = null;
            $record->save();
            $this->grantRecipientAccess($actor, $office, $record);
            $this->event($actor, $office, $record, 'revised');

            return $record;
        });
    }

    public function lockedRecord(User $actor, Office $office, int $id, int $version): OfficeRecord
    {
        $record = $this->access->visible($actor, $office)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_if($record->version !== $version, 409, 'This record changed. Refresh it before continuing.');

        return $record;
    }

    public function event(User $actor, Office $office, OfficeRecord $record, string $action, ?string $comment = null, string $visibility = 'office'): void
    {
        if ($record->confidentiality === 'executive_only') {
            $visibility = 'executive_only';
        }
        OfficeEvent::query()->create(['mda_id' => $office->mda_id, 'office_id' => $office->id, 'record_id' => $record->id,
            'actor_id' => $actor->id, 'action' => $action, 'visibility' => $visibility, 'comment' => $comment,
            'snapshot' => ['record' => $record->toArray(), 'attachments' => $record->attachments()->pluck('id')->all(),
                'authority' => ['roles' => $this->access->roles($actor, $office), 'delegation_ids' => $office->delegations()->active()->where('user_id', $actor->id)->pluck('id')->all()]]]);
        $this->audit->log('executive_office.'.$action, $record, [], [], ['mda_id' => $office->mda_id, 'version' => $record->version, 'actor_id' => $actor->id]);
    }

    public function note(User $actor, Office $office, OfficeRecord $record, array $input): void
    {
        $this->access->require($actor, $office, 'notes');
        abort_if($record->archived_at, 422, 'Reopen the record before adding a note.');
        $data = Validator::make($input, ['body' => ['required', 'string', 'max:50000'], 'visibility' => ['required', Rule::in(OfficeCatalog::NOTE_VISIBILITIES)]])->validate();
        abort_unless($this->access->canSeeVisibility($actor, $office, $data['visibility']), 403);
        DB::transaction(function () use ($actor, $office, $record, $data) {
            Office::query()->whereKey($office->id)->where('mda_id', $office->mda_id)->lockForUpdate()->firstOrFail();
            $this->access->require($actor, $office, 'notes');
            $record = $this->access->visible($actor, $office)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_if($record->archived_at, 422, 'Reopen the record before adding a note.');
            $record->notes()->create($data + ['mda_id' => $office->mda_id, 'office_id' => $office->id, 'author_id' => $actor->id]);
            $this->event($actor, $office, $record, 'note_added', null, $data['visibility']);
        });
    }

    public function attach(User $actor, Office $office, OfficeRecord $record, UploadedFile $file, array $input): OfficeAttachment
    {
        $registry = in_array('registry', $this->access->roles($actor, $office), true) && ! $this->access->team($actor, $office);
        $this->access->require($actor, $office, $registry ? 'register' : ($this->access->team($actor, $office) ? 'edit' : 'respond'));
        if ($registry) {
            abort_unless((int) $record->created_by === (int) $actor->id && in_array($record->status, ['received', 'expected'], true), 403);
        }
        $data = Validator::make(['file' => $file] + $input, [
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt'],
            'visibility' => ['required', Rule::in(OfficeCatalog::NOTE_VISIBILITIES)], 'version' => ['required', 'integer'],
        ])->validate();
        abort_unless(($registry && $data['visibility'] === 'recipients') || $this->access->canSeeVisibility($actor, $office, $data['visibility'], $record), 403);
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $office, $record, $file, $data, &$path) {
                $record = $this->lockedRecord($actor, $office, $record->id, $data['version']);
                abort_if($record->archived_at || in_array($record->status, OfficeCatalog::CLOSED, true), 422, 'Reopen this record before changing its attachments.');
                $path = $file->store('executive-office/'.$office->mda_id.'/'.$office->id, 'local');
                abort_unless($path, 500, 'The attachment could not be stored.');
                $record->increment('version');
                $record->update(['approved_version' => null]);
                $attachment = $record->attachments()->create(['mda_id' => $office->mda_id, 'office_id' => $office->id, 'uploaded_by' => $actor->id,
                    'name' => basename($file->getClientOriginalName()), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'record_version' => $record->version, 'visibility' => $data['visibility']]);
                $this->event($actor, $office, $record, 'attachment_added');

                return $attachment;
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $error;
        }
    }

    public function link(User $actor, Office $office, OfficeRecord $source, int $targetId): void
    {
        $this->access->require($actor, $office, 'route');
        abort_if($source->archived_at, 422, 'Reopen the record before linking another matter.');
        $target = $this->access->record($actor, $office, $targetId);
        abort_if($source->id === $target->id, 422, 'Choose another record.');
        OfficeLink::query()->firstOrCreate(['source_id' => min($source->id, $target->id), 'target_id' => max($source->id, $target->id)],
            ['mda_id' => $office->mda_id, 'office_id' => $office->id, 'created_by' => $actor->id]);
        $this->event($actor, $office, $source, 'linked', 'A related office record was linked.');
    }

    public function payload(User $user, Office $office, OfficeRecord $record, bool $detail = false): array
    {
        $payload = $record->toArray();
        if (! $detail) {
            unset($payload['body'], $payload['details']);

            return $payload;
        }
        $payload['notes'] = $record->notes()->orderBy('id')->get()->filter(fn ($note) => $this->access->canSeeVisibility($user, $office, $note->visibility, $record))->values();
        $payload['attachments'] = $record->attachments()->orderByDesc('id')->get()->filter(fn ($attachment) => $this->access->canSeeVisibility($user, $office, $attachment->visibility, $record) || ($attachment->visibility === 'recipients' && (int) $attachment->uploaded_by === (int) $user->id))->values();
        $payload['events'] = $record->events()->orderByDesc('id')->get()->filter(fn ($event) => $this->access->canSeeVisibility($user, $office, $event->visibility, $record))
            ->map(function ($event) use ($user, $office) {
                $row = $event->toArray();
                if (! $this->access->team($user, $office)) {
                    unset($row['snapshot']);
                }

                return $row;
            })->values();
        $ids = OfficeLink::query()->where('mda_id', $office->mda_id)->where('office_id', $office->id)->where(fn ($q) => $q->where('source_id', $record->id)->orWhere('target_id', $record->id))->get()
            ->flatMap(fn ($link) => [$link->source_id, $link->target_id])->reject(fn ($id) => $id === $record->id)->unique();
        $payload['links'] = $this->access->visible($user, $office)->whereIn('id', $ids)->get(['id', 'reference', 'subject', 'type']);
        $payload['actions'] = app(OfficeWorkflowService::class)->actions($user, $office, $record);
        $payload['can_edit'] = in_array('edit', $this->access->abilities($user, $office), true) && ! $record->archived_at && ! in_array($record->status, OfficeCatalog::CLOSED, true);
        $payload['participants'] = User::query()->whereIn('id', $record->details['participant_user_ids'] ?? [])->get(['id', 'name'])->map(fn ($participant) => [
            'id' => $participant->id, 'name' => $participant->name, 'response' => $record->details['rsvps'][(string) $participant->id] ?? 'Awaiting response',
            'attendance' => in_array((int) $participant->id, $record->details['attended_user_ids'] ?? [], true) ? 'Present' : 'Not recorded',
        ]);
        $payload['warnings'] = in_array($record->type, ['appointment', 'meeting']) && app(OfficeDiaryService::class)->conflict($office, $record)
            ? ['This time overlaps another confirmed engagement.'] : [];

        return $payload;
    }
}
