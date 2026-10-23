<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Models\OfficeRecord;
use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OfficeWorkflowService
{
    public function __construct(protected OfficeAccess $access, protected OfficeRecordService $records, protected OfficeDiaryService $diary) {}

    protected function definitions(OfficeRecord $record): array
    {
        $reopened = match ($record->type) {
            'correspondence' => ($record->details['direction'] ?? '') === 'outgoing' ? 'draft' : 'received', 'directive' => 'returned', 'meeting' => 'held', 'appointment' => 'requested', 'visitor' => 'expected', default => 'draft'
        };

        return $this->domainDefinitions($record) + ['archive' => ['archive', [$record->status], $record->status], 'reopen' => ['archive', [$record->status], $reopened]];
    }

    protected function domainDefinitions(OfficeRecord $record): array
    {
        return match ($record->type) {
            'briefing' => ['publish' => ['brief', ['draft'], 'published']],
            'meeting' => [
                'confirm' => ['meetings', ['draft'], 'confirmed'], 'cancel' => ['meetings', ['draft', 'confirmed'], 'cancelled'],
                'held' => ['meetings', ['confirmed'], 'held'], 'record_attendance' => ['meetings', ['confirmed', 'held'], 'held'],
                'rsvp_accept' => ['respond', ['confirmed'], 'confirmed'], 'rsvp_decline' => ['respond', ['confirmed'], 'confirmed'],
                'submit_minutes' => ['meetings', ['held', 'returned'], 'minutes_submitted'],
                'approve_minutes' => ['decide', ['minutes_submitted'], 'approved'], 'return_minutes' => ['decide', ['minutes_submitted'], 'returned'],
            ],
            'directive' => [
                'submit_decision' => ['route', ['draft'], 'awaiting_decision'],
                'issue' => ['decide', ['draft', 'awaiting_decision'], 'assigned'],
                'acknowledge' => ['respond', ['assigned', 'returned'], 'acknowledged'],
                'start_work' => ['respond', ['assigned', 'acknowledged', 'returned', 'blocked'], 'in_progress'],
                'update_progress' => ['respond', ['assigned', 'acknowledged', 'in_progress', 'blocked', 'returned'], $record->status],
                'report_blocker' => ['respond', ['assigned', 'acknowledged', 'in_progress', 'returned'], 'blocked'],
                'request_clarification' => ['respond', ['assigned', 'acknowledged', 'in_progress', 'returned'], 'blocked'],
                'submit_response' => ['respond', ['assigned', 'acknowledged', 'in_progress', 'blocked', 'returned'], 'response_submitted'],
                'accept_response' => ['review', ['response_submitted'], 'completed'],
                'return_response' => ['review', ['response_submitted'], 'returned'],
                'cancel' => ['decide', ['draft', 'awaiting_decision', 'assigned', 'acknowledged', 'in_progress', 'blocked', 'returned'], 'cancelled'],
            ],
            'correspondence' => [
                'submit_decision' => ['route', ['received', 'draft', 'returned'], 'awaiting_decision'],
                'approve' => ['decide', ['awaiting_decision'], ($record->details['direction'] ?? '') === 'outgoing' ? 'approved' : 'completed'],
                'return' => ['decide', ['awaiting_decision', 'response_submitted'], 'returned'],
                'reject' => ['decide', ['awaiting_decision'], 'rejected'],
                'refer' => ['decide', ['received', 'awaiting_decision', 'assigned', 'awaiting_response', 'response_submitted'], 'assigned'],
                'clarify' => ['decide', ['awaiting_decision', 'response_submitted'], 'awaiting_response'],
                'defer' => ['decide', ['awaiting_decision'], 'awaiting_decision'],
                'noted' => ['decide', ['awaiting_decision'], 'completed'],
                'respond' => ['respond', ['assigned', 'awaiting_response'], 'response_submitted'],
                'accept_response' => ['review', ['response_submitted'], 'completed'],
                'dispatch' => ['dispatch', ['approved'], 'dispatched'],
            ],
            'appointment' => [
                'tentative' => ['schedule', ['requested'], 'tentative'], 'confirm' => ['schedule', ['requested', 'tentative'], 'confirmed'],
                'decline' => ['schedule', ['requested', 'tentative'], 'declined'], 'cancel' => ['schedule', ['requested', 'tentative', 'confirmed'], 'cancelled'],
                'reschedule' => ['schedule', ['requested', 'tentative', 'confirmed'], 'requested'], 'complete' => ['schedule', ['confirmed'], 'completed'],
                'convert_to_meeting' => ['meetings', ['requested', 'tentative', 'confirmed'], $record->status === 'confirmed' ? 'confirmed' : 'draft'],
            ],
            'visitor' => ['confirm_host' => ['schedule', ['expected', 'arrived'], $record->status], 'arrive' => ['register', ['expected'], 'arrived'], 'depart' => ['register', ['arrived'], 'departed'], 'cancel' => ['register', ['expected'], 'cancelled']],
            default => [],
        };
    }

    public function actions(User $actor, Office $office, OfficeRecord $record): array
    {
        $abilities = $this->access->abilities($actor, $office);
        $actions = [];
        foreach ($this->definitions($record) as $key => [$ability, $states]) {
            if ($record->archived_at && $key !== 'reopen') {
                continue;
            }
            if ($key === 'archive' && (! $record->closed_at || $record->archived_at)) {
                continue;
            }
            if ($key === 'reopen' && ! $record->closed_at && ! $record->archived_at && ! in_array($record->status, OfficeCatalog::CLOSED, true)) {
                continue;
            }
            if (! in_array($ability, $abilities, true) || ! in_array($record->status, $states, true)) {
                continue;
            }
            if (in_array($key, ['respond', 'acknowledge', 'start_work', 'update_progress', 'report_blocker', 'request_clarification', 'submit_response'], true) && (int) $record->assigned_to !== (int) $actor->id) {
                continue;
            }
            if (in_array($key, ['rsvp_accept', 'rsvp_decline'], true) && ! in_array((int) $actor->id, $record->details['participant_user_ids'] ?? [], true)) {
                continue;
            }
            if ($key === 'confirm' && in_array($record->type, ['appointment', 'meeting'], true)
                && (($record->type === 'appointment' && ! $office->pa_confirms_routine) || $this->diary->exceptional($office, $record)) && ! in_array('decide', $abilities, true)) {
                continue;
            }
            $actions[] = $key;
        }

        return $actions;
    }

    public function act(User $actor, Office $office, OfficeRecord $record, array $input): OfficeRecord
    {
        $data = Validator::make($input, ['action' => ['required', 'string'], 'version' => ['required', 'integer'], 'comment' => ['required_if:action,return,reject,refer,clarify,defer,respond,cancel,decline,reschedule,submit_response,return_response,report_blocker,request_clarification,return_minutes,reopen,update_progress', 'nullable', 'string', 'max:50000'],
            'starts_at' => ['required_if:action,reschedule', 'nullable', 'date'], 'ends_at' => ['required_if:action,reschedule', 'nullable', 'date', 'after:starts_at'],
            'outcome' => ['required_if:action,depart', 'nullable', 'string', 'max:5000'], 'received_by_executive' => ['required_if:action,depart', 'nullable', 'boolean'],
            'assigned_to' => ['required_if:action,refer,clarify', 'nullable', 'integer', 'min:1'], 'department_id' => ['nullable', 'integer', 'min:1'],
            'due_at' => ['required_if:action,refer,clarify,defer', 'nullable', 'date'],
            'dispatch_method' => ['required_if:action,dispatch', 'nullable', 'string', 'max:100'],
            'dispatch_recipient' => ['required_if:action,dispatch', 'nullable', 'string', 'max:255'],
            'dispatch_evidence' => ['nullable', 'string', 'max:5000'],
            'agenda' => ['required_if:action,convert_to_meeting', 'nullable', 'string', 'max:50000'],
            'attended_user_ids' => ['present_if:action,record_attendance', 'nullable', 'array', 'max:100'], 'attended_user_ids.*' => ['integer', 'min:1', 'distinct'],
            'external_attendance' => ['nullable', 'string', 'max:10000'],
        ])->validate();
        $this->access->require($actor, $office, 'read');

        return DB::transaction(function () use ($actor, $office, $record, $data) {
            $office = Office::query()->whereKey($office->id)->where('mda_id', $office->mda_id)->lockForUpdate()->firstOrFail();
            $record = $this->records->lockedRecord($actor, $office, $record->id, $data['version']);
            abort_unless(in_array($data['action'], $this->actions($actor, $office, $record), true), 403, 'This action is not permitted in the current state.');
            [, , $status] = $this->definitions($record)[$data['action']];
            if ($data['action'] === 'archive') {
                $record->archived_at = now();
            }
            if ($data['action'] === 'reopen') {
                $record->archived_at = null;
                $record->closed_at = null;
                $record->approved_version = null;
            }
            if ($data['action'] === 'convert_to_meeting') {
                $record->type = 'meeting';
                $record->details = array_replace($record->details, ['agenda' => $data['agenda']]);
            }
            if ($data['action'] === 'confirm_host') {
                $record->details = array_replace($record->details, ['host_confirmed_at' => now()->toIso8601String(), 'host_confirmed_by' => $actor->id]);
            }
            if ($data['action'] === 'submit_minutes') {
                abort_unless(trim($record->details['minutes'] ?? '') !== '', 422, 'Enter the draft minutes before submitting them.');
            }
            if (in_array($data['action'], ['rsvp_accept', 'rsvp_decline'], true)) {
                $details = $record->details;
                $details['rsvps'][(string) $actor->id] = $data['action'] === 'rsvp_accept' ? 'Accepted' : 'Declined';
                $record->details = $details;
            }
            if ($data['action'] === 'record_attendance') {
                $ids = array_map('intval', $data['attended_user_ids'] ?? []);
                abort_if(array_diff($ids, $record->details['participant_user_ids'] ?? []), 422, 'Attendance must match the meeting participant list.');
                $record->details = array_replace($record->details, ['attended_user_ids' => $ids, 'external_attendance' => $data['external_attendance'] ?? null]);
            }
            if (in_array($data['action'], ['refer', 'clarify'], true)) {
                $this->records->validatePeople($office, $data);
                $record->assigned_to = $data['assigned_to'];
                $record->department_id = $data['department_id'] ?? null;
                $record->due_at = Carbon::parse($data['due_at'], $office->timezone)->utc();
                $this->records->grantRecipientAccess($actor, $office, $record);
            }
            if ($data['action'] === 'defer') {
                $record->due_at = Carbon::parse($data['due_at'], $office->timezone)->utc();
            }
            if ($data['action'] === 'dispatch') {
                abort_unless($record->approved_version === $record->version, 422, 'This document version must be approved before dispatch.');
                $record->details = array_replace($record->details, ['dispatch_method' => $data['dispatch_method'], 'dispatch_recipient' => $data['dispatch_recipient'], 'dispatch_evidence' => $data['dispatch_evidence'] ?? null, 'dispatched_at' => now()->toIso8601String(), 'dispatched_by' => $actor->id]);
            }
            if ($data['action'] === 'confirm') {
                $this->diary->confirm($actor, $office, $record);
            }
            if ($data['action'] === 'reschedule') {
                $record->starts_at = Carbon::parse($data['starts_at'], $office->timezone)->utc();
                $record->ends_at = Carbon::parse($data['ends_at'], $office->timezone)->utc();
            }
            if (in_array($data['action'], ['arrive', 'depart'], true)) {
                $details = $record->details;
                $details[$data['action'] === 'arrive' ? 'arrived_at' : 'departed_at'] = now()->toIso8601String();
                if ($data['action'] === 'depart') {
                    $details['outcome'] = $data['outcome'];
                    $details['received_by_executive'] = $data['received_by_executive'];
                }
                $record->details = $details;
            }
            $record->status = $status;
            $record->version++;
            if ($data['action'] === 'issue') {
                $this->records->grantRecipientAccess($actor, $office, $record);
            }
            if (in_array($data['action'], ['approve', 'approve_minutes'], true)) {
                $record->approved_version = $record->version;
            }
            if ($data['action'] !== 'archive') {
                $record->closed_at = in_array($status, ['completed', 'departed', 'cancelled', 'declined', 'rejected', 'dispatched', 'published'], true) || ($record->type === 'meeting' && $status === 'approved') ? now() : null;
            }
            $record->save();
            $this->records->event($actor, $office, $record, $data['action'], $data['comment'] ?? null, 'recipients');
            app(OfficeNotificationService::class)->notify($office, $record, 'activity', (string) $record->version);

            return $record;
        });
    }

    public function directives(User $actor, Office $office, OfficeRecord $source, array $input): array
    {
        $this->access->require($actor, $office, 'create');
        abort_unless(in_array($source->type, ['correspondence', 'meeting'], true), 422);
        $data = Validator::make($input, ['version' => ['required', 'integer'], 'actions' => ['required', 'array', 'min:1', 'max:20'], 'actions.*' => ['required', 'array']])->validate();

        return DB::transaction(function () use ($actor, $office, $source, $data) {
            Office::query()->whereKey($office->id)->where('mda_id', $office->mda_id)->lockForUpdate()->firstOrFail();
            $source = $this->records->lockedRecord($actor, $office, $source->id, $data['version']);
            abort_if($source->archived_at, 422, 'Reopen the source record first.');
            if ($source->type === 'meeting') {
                abort_unless($source->status === 'approved', 422, 'Approve the meeting minutes before preparing follow-up actions.');
            }
            $created = [];
            foreach ($data['actions'] as $action) {
                $child = $this->records->create($actor, $office, array_replace($action, ['type' => 'directive']));
                $this->records->link($actor, $office, $source, $child->id);
                if (in_array('decide', $this->access->abilities($actor, $office), true)) {
                    $child = $this->act($actor, $office, $child, ['action' => 'issue', 'version' => $child->version, 'comment' => 'Issued from an executive decision.']);
                }
                $created[] = $child;
            }
            $source->increment('version');
            $this->records->event($actor, $office, $source, 'follow_up_actions_created', count($created).' follow-up actions prepared.');

            return $created;
        });
    }
}
