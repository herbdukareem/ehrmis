<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Models\OfficeMembership;
use App\Domain\ExecutiveOffice\Models\OfficeRecord;
use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Domain\Module\Services\ModuleAccessService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OfficeAccess
{
    public function roles(User $user, Office $office): array
    {
        if (! $user->canAccessMda($office->mda_id) || $user->status?->value !== 'active') {
            return [];
        }

        return $office->memberships()->active()->where('user_id', $user->id)->pluck('role')->all();
    }

    public function abilities(User $user, Office $office): array
    {
        if (! $user->canAccessMda($office->mda_id) || $user->status?->value !== 'active') {
            return [];
        }
        $abilities = collect($this->roles($user, $office))->flatMap(fn ($role) => OfficeCatalog::ROLES[$role] ?? []);
        $delegated = $office->delegations()->active()->where('user_id', $user->id)->get()->flatMap->abilities;

        return $abilities->merge($delegated)->unique()->values()->all();
    }

    public function executive(User $user, Office $office): bool
    {
        return in_array('executive', $this->roles($user, $office), true);
    }

    public function executiveContent(User $user, Office $office): bool
    {
        return $this->executive($user, $office) || ($user->canAccessMda($office->mda_id)
            && $user->status?->value === 'active'
            && $office->delegations()->active()->where('user_id', $user->id)->where('executive_only_access', true)->exists());
    }

    public function team(User $user, Office $office): bool
    {
        return count(array_intersect($this->roles($user, $office), ['executive', 'lead_pa', 'pa'])) > 0
            || $office->delegations()->active()->where('user_id', $user->id)->exists();
    }

    public function configure(User $user, int $mdaId): bool
    {
        return $user->status?->value === 'active' && $user->canAccessMda($mdaId)
            && ($user->can('manage-executive-office') || $user->can('manage-mda-settings')
                || OfficeMembership::query()->where('mda_id', $mdaId)->where('user_id', $user->id)->where('role', 'administrator')->active()->exists());
    }

    public function assertEnabled(User $user, Office $office): void
    {
        abort_unless($user->canAccessMda($office->mda_id)
            && app(ModuleAccessService::class)->mdaHasModule($office->mda_id, 'executive_office'), 403);
    }

    public function require(User $user, Office $office, string $ability): void
    {
        $this->assertEnabled($user, $office);
        abort_unless(in_array($ability, $this->abilities($user, $office), true), 403, 'Your current office assignment does not permit this action.');
    }

    public function visible(User $user, Office $office): Builder
    {
        $query = OfficeRecord::query()->where('mda_id', $office->mda_id)->where('office_id', $office->id);
        if (! in_array('read', $this->abilities($user, $office), true)) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->executiveContent($user, $office)) {
            return $query;
        }
        $query->where('confidentiality', '!=', 'executive_only');
        if ($this->team($user, $office)) {
            return $query;
        }
        $roles = $this->roles($user, $office);

        return $query->where('confidentiality', 'standard')->where(function ($q) use ($user, $roles) {
            if (in_array('action_officer', $roles, true)) {
                $q->where(fn ($r) => $r->where('assigned_to', $user->id)->where(fn ($s) => $s->where('type', '!=', 'directive')->orWhereNotIn('status', ['draft', 'awaiting_decision'])))
                    ->orWhere(fn ($r) => $r->whereIn('type', ['meeting', 'appointment'])
                        ->whereNotIn('status', ['draft', 'requested', 'tentative', 'cancelled', 'declined'])
                        ->whereJsonContains('details->participant_user_ids', (int) $user->id));
            }
            if (in_array('registry', $roles, true)) {
                $q->orWhere('type', 'visitor')->orWhere(fn ($r) => $r->where('created_by', $user->id)->where('type', 'correspondence'));
            }
            if (! array_intersect($roles, ['action_officer', 'registry'])) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public function record(User $user, Office $office, int $id): OfficeRecord
    {
        $this->require($user, $office, 'read');

        return $this->visible($user, $office)->whereKey($id)->firstOrFail();
    }

    public function canSeeVisibility(User $user, Office $office, string $visibility, ?OfficeRecord $record = null): bool
    {
        return match ($visibility) {
            'executive_only' => $this->executiveContent($user, $office),
            'office' => $this->team($user, $office),
            'recipients' => $record === null || $this->team($user, $office) || (int) $record->assigned_to === (int) $user->id
                || in_array((int) $user->id, $record->details['participant_user_ids'] ?? [], true),
            default => false,
        };
    }
}
