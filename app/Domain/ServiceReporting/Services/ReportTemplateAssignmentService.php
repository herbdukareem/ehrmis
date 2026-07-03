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
        return ReportTemplate::query()
            ->active()
            ->with(['ownerMda', 'sections.indicators.dimensions', 'assignments.mda', 'assignments.station'])
            ->whereHas('assignments', function (Builder $query) use ($user, $mdaId): void {
                $query->active();

                if ($mdaId) {
                    $query->where('mda_id', $mdaId);
                    return;
                }

                if (! $user->hasGlobalMdaAccess()) {
                    $query->whereIn('mda_id', $user->accessibleMdaIds()->all());
                }
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (ReportTemplate $template): bool => $template->assignments
                ->contains(fn (ReportTemplateAssignment $assignment): bool => $this->moduleAccess->userCanAccessModule($user, 'service_reporting', (int) $assignment->mda_id)))
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
        $template->loadMissing('assignments');

        return $template->assignments
            ->where('status', 'active')
            ->contains(function (ReportTemplateAssignment $assignment) use ($user, $mdaId): bool {
                if ($mdaId && (int) $assignment->mda_id !== $mdaId) {
                    return false;
                }

                return $user->canAccessMda((int) $assignment->mda_id)
                    && $this->moduleAccess->userCanAccessModule($user, 'service_reporting', (int) $assignment->mda_id);
            });
    }
}
