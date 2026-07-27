<?php

namespace App\Domain\ServiceReporting\Services;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Mda;
use App\Domain\ServiceReporting\Models\ReportTemplate;
use App\Domain\ServiceReporting\Models\ReportTemplateAssignment;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportTemplateAssignmentService
{
    public function __construct(
        protected ModuleAccessService $moduleAccess,
        protected AuditLogService $auditLogService,
    ) {
    }

    public function availableTemplatesFor(User $user, ?int $mdaId = null): Collection
    {
        $user->loadMissing('station');

        return ReportTemplate::query()
            ->active()
            ->with(['ownerMda', 'sections.indicators.dimensions', 'assignments.mda', 'assignments.station', 'assignments.department'])
            ->whereHas('assignments', function (Builder $query) use ($user, $mdaId): void {
                $this->scopeAssignmentsVisibleToUser($query, $user, $mdaId);
            })
            ->orderBy('name')
            ->get()
            ->map(function (ReportTemplate $template) use ($user, $mdaId): ReportTemplate {
                $template->setRelation('assignments', $this->visibleAssignmentsFor($user, $template, $mdaId)->values());

                return $template;
            })
            ->filter(fn (ReportTemplate $template): bool => $template->assignments->isNotEmpty())
            ->values();
    }

    public function visibleAssignmentsFor(User $user, ReportTemplate $template, ?int $mdaId = null): Collection
    {
        $user->loadMissing('station');
        $template->loadMissing('assignments.mda', 'assignments.station', 'assignments.department');

        return $template->assignments
            ->where('status', 'active')
            ->filter(fn (ReportTemplateAssignment $assignment): bool => $this->assignmentVisibleToUser($assignment, $user, $mdaId))
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     */
    public function syncAssignments(ReportTemplate $template, array $assignments, User $actor): Collection
    {
        return DB::transaction(function () use ($template, $assignments, $actor): Collection {
            $ids = [];

            foreach ($assignments as $assignmentData) {
                if (! $actor->canAccessMda((int) $assignmentData['mda_id'])) {
                    throw ValidationException::withMessages([
                        'assignments' => 'You cannot assign templates outside your accessible MDA scope.',
                    ]);
                }

                if (! $this->moduleAccess->mdaHasModule((int) $assignmentData['mda_id'], 'service_reporting')) {
                    throw ValidationException::withMessages([
                        'assignments' => 'The selected MDA does not have Service Reporting enabled.',
                    ]);
                }

                if (! empty($assignmentData['station_id']) && ! $this->stationBelongsToMda((int) $assignmentData['station_id'], (int) $assignmentData['mda_id'])) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Selected station assignments must belong to the same MDA.',
                    ]);
                }

                $assignment = $template->assignments()->updateOrCreate(
                    [
                        'mda_id' => $assignmentData['mda_id'],
                        'station_id' => $assignmentData['station_id'] ?? null,
                        'department_id' => $assignmentData['department_id'] ?? null,
                    ],
                    [
                        'facility_type' => $assignmentData['facility_type'] ?? null,
                        'required_from' => $assignmentData['required_from'] ?? null,
                        'required_until' => $assignmentData['required_until'] ?? null,
                        'is_required' => $assignmentData['is_required'] ?? true,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                        'status' => $assignmentData['status'] ?? 'active',
                    ],
                );
                $ids[] = $assignment->id;

                $this->auditLogService->log('service_reporting.template.assigned', $assignment, [], $assignment->toArray(), [
                    'source' => 'service_reporting',
                    'template_id' => $template->id,
                    'template_code' => $template->code,
                    'mda_id' => $assignment->mda_id,
                    'station_id' => $assignment->station_id,
                    'actor_user_id' => $actor->id,
                ]);
            }

            $template->assignments()
                ->whereNotIn('id', $ids)
                ->update(['status' => 'inactive']);

            return $template->assignments()->with(['mda', 'station', 'department'])->get();
        });
    }

    public function userCanSeeTemplate(User $user, ReportTemplate $template, ?int $mdaId = null): bool
    {
        return $this->visibleAssignmentsFor($user, $template, $mdaId)->isNotEmpty();
    }

    protected function scopeAssignmentsVisibleToUser(Builder $query, User $user, ?int $mdaId = null): void
    {
        $query->active();

        if ($user->hasStationScope()) {
            $station = $user->station;

            if (! $station) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query
                ->where('mda_id', $station->mda_id)
                ->where(function (Builder $stationQuery) use ($user): void {
                    $stationQuery
                        ->whereNull('station_id')
                        ->orWhere('station_id', $user->station_id);
                });
        } elseif ($mdaId) {
            $query->where('mda_id', $mdaId);
        } elseif (! $user->hasGlobalMdaAccess()) {
            $query->whereIn('mda_id', $user->accessibleMdaIds()->all());
        }

        if ($mdaId) {
            $query->where('mda_id', $mdaId);
        }
    }

    protected function assignmentVisibleToUser(ReportTemplateAssignment $assignment, User $user, ?int $mdaId = null): bool
    {
        if ($mdaId && (int) $assignment->mda_id !== $mdaId) {
            return false;
        }

        if (! $this->moduleAccess->userCanAccessModule($user, 'service_reporting', (int) $assignment->mda_id)) {
            return false;
        }

        if ($user->hasStationScope()) {
            $station = $user->station;

            return $station !== null
                && (int) $assignment->mda_id === (int) $station->mda_id
                && ($assignment->station_id === null || (int) $assignment->station_id === (int) $user->station_id);
        }

        return $user->canAccessMda((int) $assignment->mda_id);
    }

    protected function stationBelongsToMda(int $stationId, int $mdaId): bool
    {
        return \App\Domain\Organization\Models\Station::query()
            ->whereKey($stationId)
            ->where('mda_id', $mdaId)
            ->exists();
    }
}
