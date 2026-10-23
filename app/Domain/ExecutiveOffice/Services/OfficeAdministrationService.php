<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Models\OfficeEvent;
use App\Domain\ExecutiveOffice\Models\OfficeMembership;
use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OfficeAdministrationService
{
    public function __construct(public OfficeAccess $access, protected AuditLogService $audit) {}

    public function configure(User $actor, int $mdaId, array $data): Office
    {
        abort_unless($this->access->configure($actor, $mdaId), 403);
        $mda = Mda::query()->visibleToUser($actor)->findOrFail($mdaId);
        $data = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'], 'reference_prefix' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/_.-]+$/'],
            'timezone' => ['required', 'timezone'], 'pa_confirms_routine' => ['required', 'boolean'], 'reminder_minutes' => ['required', 'integer', 'between:5,10080'],
            'workday_start' => ['sometimes', 'date_format:H:i'], 'workday_end' => ['sometimes', 'date_format:H:i', 'after:workday_start'],
            'workdays' => ['sometimes', 'array', 'min:1', 'max:7'], 'workdays.*' => ['integer', 'between:1,7', 'distinct'],
        ])->validate();

        return DB::transaction(function () use ($actor, $mda, $data) {
            $mda->newQuery()->whereKey($mda->id)->lockForUpdate()->firstOrFail();
            $office = Office::query()->updateOrCreate(['mda_id' => $mda->id], $data);
            $this->audit->log('executive_office.configured', $office, [], [], ['mda_id' => $mda->id, 'actor_id' => $actor->id]);

            return $office;
        });
    }

    public function assign(User $actor, Office $office, array $data): OfficeMembership
    {
        $this->access->assertEnabled($actor, $office);
        abort_unless($this->access->configure($actor, $office->mda_id), 403);
        $data = Validator::make($data, [
            'user_id' => ['required', 'integer'], 'role' => ['required', Rule::in(array_keys(OfficeCatalog::ROLES))],
            'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ])->validate();
        $user = User::query()->findOrFail($data['user_id']);
        $data['starts_at'] = Carbon::parse($data['starts_at'], $office->timezone)->utc();
        if (! empty($data['ends_at'])) {
            $data['ends_at'] = Carbon::parse($data['ends_at'], $office->timezone)->utc();
        }
        if (! $user->canAccessMda($office->mda_id) || $user->status?->value !== 'active') {
            throw ValidationException::withMessages(['user_id' => 'Choose an active user with access to this MDA.']);
        }

        return DB::transaction(function () use ($actor, $office, $data, $user) {
            Office::query()->whereKey($office->id)->lockForUpdate()->firstOrFail();
            $overlap = $office->memberships()->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $data['starts_at']))
                ->when($data['ends_at'] ?? null, fn ($q, $end) => $q->where('starts_at', '<', $end));
            if (in_array($data['role'], ['executive', 'lead_pa'], true)) {
                $overlap->where('role', $data['role']);
            } else {
                $overlap->where('user_id', $user->id)->where('role', $data['role']);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['role' => 'An overlapping assignment already exists. End or revoke it first.']);
            }
            $member = $office->memberships()->create($data + ['mda_id' => $office->mda_id, 'assigned_by' => $actor->id]);
            $user->givePermissionTo('view-executive-office');
            $this->event($office, $actor, 'member_assigned', ['membership_id' => $member->id, 'user_id' => $user->id, 'role' => $member->role]);

            return $member;
        });
    }

    public function revoke(User $actor, Office $office, int $id): void
    {
        $this->access->assertEnabled($actor, $office);
        abort_unless($this->access->configure($actor, $office->mda_id), 403);
        $member = $office->memberships()->where('mda_id', $office->mda_id)->findOrFail($id);
        $member->update(['revoked_at' => now()]);
        $this->event($office, $actor, 'member_revoked', ['membership_id' => $id]);
    }

    public function event(Office $office, User $actor, string $action, array $snapshot = []): void
    {
        OfficeEvent::query()->create(['mda_id' => $office->mda_id, 'office_id' => $office->id, 'actor_id' => $actor->id, 'action' => $action, 'snapshot' => $snapshot]);
        $this->audit->log('executive_office.'.$action, $office, [], [], ['mda_id' => $office->mda_id, 'actor_id' => $actor->id]);
    }
}
