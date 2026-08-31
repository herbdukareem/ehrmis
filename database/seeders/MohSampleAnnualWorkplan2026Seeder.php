<?php

namespace Database\Seeders;

use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanEvidence;
use App\Domain\Workplan\Models\WorkplanIndicatorProgress;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use App\Domain\Workplan\Services\WorkplanIndicatorAchievementService;
use App\Enums\WorkplanProgressStatus;
use App\Enums\WorkplanStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Data\MohSampleAnnualWorkplan2026Data;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MohSampleAnnualWorkplan2026Seeder extends Seeder
{
    public function run(): void
    {
        $payload = MohSampleAnnualWorkplan2026Data::payload();

        DB::transaction(function () use ($payload): void {
            $mda = $this->ensureMda($payload['mda']);
            $station = $this->ensureHeadquartersStation($mda);
            $departments = $this->ensureDepartments($mda, $payload['departments']);
            $staffDirectory = $this->ensureStaffDirectory($mda, $station, $departments, $payload['staff_directory']);
            $actors = $this->resolveActors($mda);

            $baseObjectives = $payload['objectives'];
            $amendedObjectives = $this->amendedObjectives($baseObjectives, $payload['amendment_revision']);

            $revisionOne = $this->ensureWorkplan(
                $mda,
                $payload,
                1,
                $actors['author'],
                null,
                null,
                $this->buildSummary($payload, $baseObjectives)
            );
            $revisionTwo = $this->ensureWorkplan(
                $mda,
                $payload,
                2,
                $actors['author'],
                $revisionOne->id,
                $payload['amendment_revision']['reason'],
                $this->buildSummary($payload, $amendedObjectives, [
                    'amendment_reason' => $payload['amendment_revision']['reason'],
                    'amendment_changes' => $payload['amendment_revision']['activity_updates'],
                ])
            );

            $this->resetWorkplan($revisionOne);
            $this->resetWorkplan($revisionTwo);

            $this->seedStructure($revisionOne, $baseObjectives, $departments, $staffDirectory);
            $this->seedStructure($revisionTwo, $amendedObjectives, $departments, $staffDirectory);
            $this->seedQuarterOneScenario($revisionOne, $payload['progress_scenarios']['q1'], $actors['author'], $actors['reviewer'], $actors['approver']);

            $revisionOneTimeline = [
                'draft_submitted_at' => CarbonImmutable::parse('2026-01-21 09:00:00'),
                'draft_reviewed_at' => CarbonImmutable::parse('2026-01-24 11:00:00'),
                'draft_returned_at' => CarbonImmutable::parse('2026-01-27 14:00:00'),
                'submitted_at' => CarbonImmutable::parse('2026-02-02 09:00:00'),
                'reviewed_at' => CarbonImmutable::parse('2026-02-05 10:00:00'),
                'approved_at' => CarbonImmutable::parse('2026-02-06 13:00:00'),
                'activated_at' => CarbonImmutable::parse('2026-02-10 08:00:00'),
            ];
            $revisionTwoTimeline = [
                'submitted_at' => CarbonImmutable::parse('2026-07-10 09:00:00'),
                'reviewed_at' => CarbonImmutable::parse('2026-07-15 10:00:00'),
                'approved_at' => CarbonImmutable::parse('2026-07-16 12:30:00'),
                'activated_at' => CarbonImmutable::parse('2026-07-20 08:30:00'),
            ];

            $this->syncWorkflow(
                $revisionOne,
                $actors['author'],
                $actors['reviewer'],
                $actors['approver'],
                $revisionOneTimeline,
                [[
                    'status' => 'returned',
                    'submitted_at' => $revisionOneTimeline['draft_submitted_at']->toISOString(),
                    'recorded_at' => $revisionOneTimeline['draft_returned_at']->toISOString(),
                    'steps' => [
                        $this->historicalStep(1, 'approved', 'Approval Officer', 'review-workplans', $actors['reviewer']?->id, $revisionOneTimeline['draft_reviewed_at']),
                        $this->historicalStep(2, 'returned', 'Approval Officer', 'approve-workplans', $actors['approver']?->id ?? $actors['reviewer']?->id, $revisionOneTimeline['draft_returned_at'], 'Complete the Q3 target.'),
                    ],
                ]]
            );
            $this->syncWorkflow($revisionTwo, $actors['author'], $actors['reviewer'], $actors['approver'], $revisionTwoTimeline);

            $revisionOne->forceFill([
                'status' => WorkplanStatus::SUPERSEDED,
                'prepared_by' => $actors['author']?->id,
                'submitted_by' => $actors['author']?->id,
                'submitted_at' => $revisionOneTimeline['submitted_at'],
                'approved_by' => $actors['approver']?->id ?? $actors['reviewer']?->id,
                'approved_at' => $revisionOneTimeline['approved_at'],
                'activated_by' => $actors['approver']?->id ?? $actors['reviewer']?->id,
                'activated_at' => $revisionOneTimeline['activated_at'],
                'superseded_at' => $revisionTwoTimeline['activated_at'],
                'superseded_by_workplan_id' => $revisionTwo->id,
            ])->save();

            $revisionTwo->forceFill([
                'status' => WorkplanStatus::ACTIVE,
                'prepared_by' => $actors['author']?->id,
                'submitted_by' => $actors['author']?->id,
                'submitted_at' => $revisionTwoTimeline['submitted_at'],
                'approved_by' => $actors['approver']?->id ?? $actors['reviewer']?->id,
                'approved_at' => $revisionTwoTimeline['approved_at'],
                'activated_by' => $actors['approver']?->id ?? $actors['reviewer']?->id,
                'activated_at' => $revisionTwoTimeline['activated_at'],
                'supersedes_workplan_id' => $revisionOne->id,
                'amendment_reason' => $payload['amendment_revision']['reason'],
                'superseded_at' => null,
                'superseded_by_workplan_id' => null,
            ])->save();
        });
    }

    protected function ensureMda(array $mdaData): Mda
    {
        return Mda::query()->updateOrCreate(
            ['code' => $mdaData['code']],
            ['name' => $mdaData['name'], 'status' => 'active'],
        );
    }

    protected function ensureHeadquartersStation(Mda $mda): Station
    {
        $station = Station::withoutGlobalScopes()->withTrashed()->firstOrNew([
            'mda_id' => $mda->id,
            'code' => 'HQ',
        ]);

        $station->fill([
            'name' => $mda->code.' Headquarters',
            'description' => 'Default headquarters station for seeded workplan staff.',
            'status' => 'active',
            'deleted_at' => null,
        ]);

        $station->save();

        return $station;
    }

    protected function ensureDepartments(Mda $mda, array $departmentRows): array
    {
        $departments = [];

        foreach ($departmentRows as $row) {
            $department = Department::withoutGlobalScopes()->withTrashed()->firstOrNew([
                'mda_id' => $mda->id,
                'code' => $row['code'],
            ]);

            $department->fill([
                'name' => $row['name'],
                'description' => $row['name'].' department for seeded workplan data.',
                'status' => 'active',
                'deleted_at' => null,
            ]);

            $department->save();
            $departments[$row['code']] = $department;
        }

        return $departments;
    }

    protected function ensureStaffDirectory(Mda $mda, Station $station, array $departments, array $directory): array
    {
        $staffMap = [];

        foreach ($directory as $row) {
            $staff = Staff::withoutGlobalScopes()->withTrashed()->firstOrNew([
                'staff_number' => $row['ref'],
            ]);

            [$surname, $firstName, $middleName] = $this->splitName($row['name']);

            $staff->fill([
                'mda_id' => $mda->id,
                'surname' => $surname,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'full_name' => $row['name'],
                'sex' => $this->guessSex($row['name']),
                'status' => 'active',
                'deleted_at' => null,
            ]);

            $staff->save();

            StaffEmployment::query()->updateOrCreate(
                [
                    'staff_id' => $staff->id,
                    'is_current' => true,
                ],
                [
                    'mda_id' => $mda->id,
                    'department_id' => $departments[$row['department_code']]->id,
                    'station_id' => $station->id,
                    'staff_category' => 'Civil Service',
                    'date_first_appointment' => '2020-01-01',
                    'date_last_promotion' => '2024-01-01',
                    'employment_status' => 'active',
                    'effective_from' => '2025-01-01',
                    'effective_to' => null,
                ]
            );

            $staffMap[$row['ref']] = $staff->fresh('currentEmployment.department');
        }

        return $staffMap;
    }

    protected function resolveActors(Mda $mda): array
    {
        $superAdmin = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'Super Admin'))->orderBy('id')->first();
        $mdaAdmin = User::query()
            ->where('mda_id', $mda->id)
            ->whereHas('roles', fn ($query) => $query->where('name', 'MDA Admin'))
            ->orderBy('id')
            ->first();
        $approvalUsers = User::query()
            ->where('mda_id', $mda->id)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Approval Officer'))
            ->orderBy('id')
            ->get()
            ->values();
        $fallback = User::query()->orderBy('id')->first();

        return [
            'author' => $mdaAdmin ?? $superAdmin ?? $fallback,
            'reviewer' => $approvalUsers->get(0) ?? $superAdmin ?? $mdaAdmin ?? $fallback,
            'approver' => $approvalUsers->get(1) ?? $approvalUsers->get(0) ?? $superAdmin ?? $mdaAdmin ?? $fallback,
        ];
    }

    protected function ensureWorkplan(
        Mda $mda,
        array $payload,
        int $revisionNo,
        ?User $author,
        ?int $supersedesWorkplanId,
        ?string $amendmentReason,
        array $summary
    ): Workplan {
        return Workplan::query()->updateOrCreate(
            [
                'mda_id' => $mda->id,
                'year' => $payload['workplan']['year'],
                'revision_no' => $revisionNo,
            ],
            [
                'title' => $payload['workplan']['title'],
                'document_classification' => $payload['workplan']['document_classification'] ?? $payload['workplan']['classification'] ?? null,
                'description' => $payload['workplan']['description'],
                'prepared_by_label' => $payload['workplan']['prepared_by_label'] ?? null,
                'overall_goal' => $payload['workplan']['overall_goal'] ?? null,
                'strategic_directions' => $payload['workplan']['strategic_directions'] ?? null,
                'planning_assumptions' => $payload['workplan']['planning_assumptions'] ?? $payload['workplan']['assumptions'] ?? null,
                'status' => WorkplanStatus::DRAFT,
                'prepared_by' => $author?->id,
                'supersedes_workplan_id' => $supersedesWorkplanId,
                'amendment_reason' => $amendmentReason,
                'summary' => $summary,
            ]
        );
    }

    protected function resetWorkplan(Workplan $workplan): void
    {
        Storage::disk('local')->deleteDirectory("workplans/{$workplan->mda_id}/{$workplan->id}/seeded-sample");

        $workflowIds = ApprovalWorkflow::query()
            ->where('subject_type', Workplan::class)
            ->where('subject_id', $workplan->id)
            ->pluck('id');

        if ($workflowIds->isNotEmpty()) {
            ApprovalStep::query()->whereIn('workflow_id', $workflowIds)->delete();
            ApprovalWorkflow::query()->whereIn('id', $workflowIds)->delete();
        }

        $reportIds = WorkplanProgressReport::query()
            ->where('workplan_id', $workplan->id)
            ->pluck('id');

        if ($reportIds->isNotEmpty()) {
            WorkplanEvidence::query()->whereIn('workplan_progress_report_id', $reportIds)->delete();
            WorkplanIndicatorProgress::query()->whereIn('workplan_progress_report_id', $reportIds)->delete();
            WorkplanProgressReport::query()->whereIn('id', $reportIds)->delete();
        }

        WorkplanEvidence::query()->where('workplan_id', $workplan->id)->delete();
        $workplan->objectives()->delete();
    }

    protected function seedStructure(Workplan $workplan, array $objectives, array $departments, array $staffDirectory): void
    {
        foreach ($objectives as $objectiveIndex => $objectiveData) {
            $objective = $workplan->objectives()->create([
                'mda_id' => $workplan->mda_id,
                'department_id' => $departments[$objectiveData['department_code']]->id ?? null,
                'code' => $objectiveData['code'],
                'title' => $objectiveData['title'],
                'lead_scope' => $objectiveData['scope_label'],
                'description' => $objectiveData['description'],
                'priority' => null,
                'performance_weight' => $objectiveData['performance_weight'],
                'planned_cost' => $objectiveData['planned_cost'] ?? null,
                'sort_order' => ($objectiveIndex + 1) * 10,
            ]);

            foreach ($objectiveData['activities'] as $activityIndex => $activityData) {
                $activity = $objective->activities()->create([
                    'mda_id' => $workplan->mda_id,
                    'department_id' => $departments[$activityData['department_code']]->id ?? null,
                    'responsible_staff_id' => $staffDirectory[$activityData['responsible_ref']]->id ?? null,
                    'activity_code' => $activityData['code'],
                    'title' => $activityData['title'],
                    'description' => $activityData['description'],
                    'expected_output' => $activityData['expected_output'],
                    'start_date' => $activityData['start_date'],
                    'end_date' => $activityData['end_date'],
                    'planned_cost' => $activityData['planned_cost'],
                    'funding_source' => $activityData['funding_source'],
                    'status' => 'not_started',
                    'performance_weight' => $activityData['performance_weight'],
                    'sort_order' => ($activityIndex + 1) * 10,
                ]);

                foreach ($activityData['supporting_refs'] as $supportingRef) {
                    if (! isset($staffDirectory[$supportingRef])) {
                        continue;
                    }

                    $activity->supportAssignments()->create([
                        'mda_id' => $workplan->mda_id,
                        'staff_id' => $staffDirectory[$supportingRef]->id,
                        'role' => 'support',
                    ]);
                }

                foreach ($activityData['indicators'] as $indicatorIndex => $indicatorData) {
                    $indicator = $activity->indicators()->create([
                        'mda_id' => $workplan->mda_id,
                        'code' => $indicatorData['code'],
                        'indicator' => $indicatorData['label'],
                        'description' => $indicatorData['description'] ?? null,
                        'unit' => $indicatorData['unit'],
                        'baseline_value' => $indicatorData['baseline'],
                        'annual_target_value' => $indicatorData['annual_target'],
                        'target_mode' => $indicatorData['mode'],
                        'direction' => $indicatorData['direction'],
                        'weight' => $indicatorData['weight'],
                        'sort_order' => ($indicatorIndex + 1) * 10,
                        'is_required' => true,
                    ]);

                    foreach ($indicatorData['targets'] as $period => $targetValue) {
                        $indicator->targets()->create([
                            'mda_id' => $workplan->mda_id,
                            'period' => $period,
                            'target_value' => $targetValue,
                        ]);
                    }
                }
            }
        }
    }

    protected function seedQuarterOneScenario(
        Workplan $workplan,
        array $scenarios,
        ?User $author,
        ?User $reviewer,
        ?User $approver
    ): void {
        $achievement = app(WorkplanIndicatorAchievementService::class);
        $activityMap = $workplan->fresh('objectives.activities.indicators.targets')
            ->objectives
            ->flatMap->activities
            ->keyBy('activity_code');

        foreach (array_values($scenarios) as $scenarioIndex => $scenario) {
            $activity = $activityMap->get($scenario['activity_code']);

            if (! $activity) {
                continue;
            }

            $submittedAt = CarbonImmutable::parse('2026-04-08 09:00:00')->addHours($scenarioIndex * 2);
            $verifiedAt = $submittedAt->addDay();
            $returnedAt = $submittedAt->addDay();

            $report = WorkplanProgressReport::query()->create([
                'mda_id' => $workplan->mda_id,
                'workplan_id' => $workplan->id,
                'workplan_activity_id' => $activity->id,
                'period' => 'q1',
                'status' => WorkplanProgressStatus::from($scenario['status']),
                'reported_expenditure' => $scenario['reported_expenditure'],
                'achievement_summary' => $scenario['achievement_summary'],
                'challenges' => $scenario['status'] === 'returned' ? 'Review comments require a corrected supporting narrative.' : null,
                'corrective_action' => $scenario['status'] === 'returned' ? 'Update the report with clearer closure evidence and revised notes.' : null,
                'next_period_action' => 'Continue implementation and follow up on corrective actions in the next reporting cycle.',
                'remarks' => 'Seeded Q1 sample scenario.',
                'prepared_by' => $author?->id,
                'submitted_by' => in_array($scenario['status'], ['submitted', 'returned', 'verified'], true) ? $author?->id : null,
                'submitted_at' => in_array($scenario['status'], ['submitted', 'returned', 'verified'], true) ? $submittedAt : null,
                'verified_by' => $scenario['status'] === 'verified' ? ($approver?->id ?? $reviewer?->id) : null,
                'verified_at' => $scenario['status'] === 'verified' ? $verifiedAt : null,
                'returned_by' => $scenario['status'] === 'returned' ? ($reviewer?->id ?? $approver?->id) : null,
                'returned_at' => $scenario['status'] === 'returned' ? $returnedAt : null,
                'return_reason' => $scenario['return_reason'],
                'summary' => [
                    'seed_key' => 'moh-sample-annual-workplan-2026',
                    'scenario_period' => 'q1',
                    'scenario_status' => $scenario['status'],
                ],
            ]);

            foreach ($activity->indicators as $indicator) {
                $target = $indicator->targets->firstWhere('period', 'q1');
                $actual = $scenario['actuals'][$indicator->code] ?? null;

                WorkplanIndicatorProgress::query()->create([
                    'mda_id' => $workplan->mda_id,
                    'workplan_progress_report_id' => $report->id,
                    'workplan_indicator_id' => $indicator->id,
                    'target_value_snapshot' => $target?->target_value,
                    'actual_value' => $actual,
                    'achievement_ratio' => $achievement->ratio($indicator, $target?->target_value, $actual),
                    'verification_note' => $scenario['status'] === 'returned' ? 'Returned to preparer for correction.' : null,
                ]);
            }

            foreach ($scenario['evidence'] as $evidenceIndex => $evidenceData) {
                $this->seedEvidence($report, $activity->activity_code, $evidenceData, $evidenceIndex, $author);
            }
        }
    }

    protected function seedEvidence(
        WorkplanProgressReport $report,
        string $activityCode,
        array $evidenceData,
        int $index,
        ?User $author
    ): void {
        if ($evidenceData['type'] === 'link') {
            WorkplanEvidence::query()->create([
                'mda_id' => $report->mda_id,
                'workplan_id' => $report->workplan_id,
                'workplan_activity_id' => $report->workplan_activity_id,
                'workplan_progress_report_id' => $report->id,
                'title' => $evidenceData['title'],
                'evidence_type' => 'link',
                'external_url' => $evidenceData['url'],
                'notes' => $evidenceData['notes'],
                'uploaded_by' => $author?->id,
            ]);

            return;
        }

        $directory = "workplans/{$report->mda_id}/{$report->workplan_id}/seeded-sample/{$report->period->value}/{$activityCode}";
        $filename = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'-'.Str::slug($evidenceData['title']).'.txt';
        $path = $directory.'/'.$filename;
        Storage::disk('local')->put($path, $evidenceData['content']);

        WorkplanEvidence::query()->create([
            'mda_id' => $report->mda_id,
            'workplan_id' => $report->workplan_id,
            'workplan_activity_id' => $report->workplan_activity_id,
            'workplan_progress_report_id' => $report->id,
            'title' => $evidenceData['title'],
            'evidence_type' => 'file',
            'file_path' => $path,
            'file_mime_type' => 'text/plain',
            'file_size' => Storage::disk('local')->size($path),
            'notes' => $evidenceData['notes'],
            'uploaded_by' => $author?->id,
        ]);
    }

    protected function amendedObjectives(array $objectives, array $amendment): array
    {
        $activityUpdates = $amendment['activity_updates'] ?? [];
        $amended = [];

        foreach ($objectives as $objective) {
            $objectiveCopy = $objective;
            $objectiveCopy['activities'] = [];

            foreach ($objective['activities'] as $activity) {
                $activityCopy = $activity;
                $update = $activityUpdates[$activity['code']] ?? null;

                if ($update) {
                    if (array_key_exists('planned_cost', $update)) {
                        $activityCopy['planned_cost'] = $update['planned_cost'];
                    }

                    if (array_key_exists('supporting_refs', $update)) {
                        $activityCopy['supporting_refs'] = $update['supporting_refs'];
                    }

                    if (! empty($update['indicator_target_updates'])) {
                        foreach ($activityCopy['indicators'] as $indicatorIndex => $indicator) {
                            $targetUpdate = $update['indicator_target_updates'][$indicator['code']] ?? null;

                            if (! $targetUpdate) {
                                continue;
                            }

                            foreach ($targetUpdate as $period => $value) {
                                $activityCopy['indicators'][$indicatorIndex]['targets'][$period] = $value;

                                if ($period === 'annual') {
                                    $activityCopy['indicators'][$indicatorIndex]['annual_target'] = $value;
                                }
                            }
                        }
                    }
                }

                $objectiveCopy['activities'][] = $activityCopy;
            }

            $amended[] = $objectiveCopy;
        }

        return $amended;
    }

    protected function buildSummary(array $payload, array $objectives, array $extra = []): array
    {
        $objectiveSummaries = array_map(function (array $objective): array {
            $plannedCost = array_sum(array_map(
                fn (array $activity): int|float => (float) ($activity['planned_cost'] ?? 0),
                $objective['activities']
            ));

            return [
                'code' => $objective['code'],
                'title' => $objective['title'],
                'scope_label' => $objective['scope_label'],
                'performance_weight' => $objective['performance_weight'],
                'planned_cost' => $plannedCost,
            ];
        }, $objectives);

        return [
            'seed_key' => $payload['seed_key'],
            'classification' => $payload['workplan']['document_classification'] ?? $payload['workplan']['classification'] ?? null,
            'prepared_by_label' => $payload['workplan']['prepared_by_label'],
            'initial_status' => $payload['workplan']['initial_status'],
            'overall_goal' => $payload['workplan']['overall_goal'],
            'strategic_directions' => $payload['workplan']['strategic_directions'],
            'assumptions' => $payload['workplan']['planning_assumptions'] ?? $payload['workplan']['assumptions'] ?? [],
            'objective_summaries' => $objectiveSummaries,
            'total_planned_cost' => array_sum(array_column($objectiveSummaries, 'planned_cost')),
            'staff_directory' => array_map(fn (array $row): array => [
                'ref' => $row['ref'],
                'name' => $row['name'],
                'role' => $row['role'],
                'department_code' => $row['department_code'],
            ], $payload['staff_directory']),
            ...$extra,
        ];
    }

    protected function syncWorkflow(
        Workplan $workplan,
        ?User $author,
        ?User $reviewer,
        ?User $approver,
        array $timeline,
        array $history = []
    ): void {
        $workflow = ApprovalWorkflow::query()->create([
            'workflow_type' => 'workplan_approval',
            'subject_type' => Workplan::class,
            'subject_id' => $workplan->id,
            'status' => 'approved',
            'submitted_by' => $author?->id,
            'submitted_at' => $timeline['submitted_at'],
            'approved_by' => $approver?->id ?? $reviewer?->id ?? $author?->id,
            'approved_at' => $timeline['approved_at'],
            'current_step_no' => 2,
            'metadata' => [
                'seed_key' => 'moh-sample-annual-workplan-2026',
                'history' => $history,
            ],
        ]);

        $workflow->steps()->create([
            'step_no' => 1,
            'reviewer_role' => 'Approval Officer',
            'status' => 'approved',
            'acted_at' => $timeline['reviewed_at'],
            'acted_by' => $reviewer?->id ?? $approver?->id ?? $author?->id,
            'metadata' => ['required_permission' => 'review-workplans'],
        ]);

        $workflow->steps()->create([
            'step_no' => 2,
            'reviewer_role' => 'Approval Officer',
            'status' => 'approved',
            'acted_at' => $timeline['approved_at'],
            'acted_by' => $approver?->id ?? $reviewer?->id ?? $author?->id,
            'metadata' => ['required_permission' => 'approve-workplans'],
        ]);
    }

    protected function historicalStep(
        int $stepNo,
        string $status,
        string $reviewerRole,
        string $requiredPermission,
        ?int $actedBy,
        CarbonImmutable $actedAt,
        ?string $comment = null
    ): array {
        return [
            'step_no' => $stepNo,
            'status' => $status,
            'reviewer_role' => $reviewerRole,
            'metadata' => ['required_permission' => $requiredPermission],
            'acted_at' => $actedAt->toISOString(),
            'acted_by' => $actedBy,
            'comment' => $comment,
        ];
    }

    protected function splitName(string $name): array
    {
        $clean = str_replace(['Dr.', 'Mrs.', 'Mr.', 'Pharm.', 'Nurse'], '', $name);
        $parts = array_values(array_filter(preg_split('/\s+/', trim($clean)) ?: []));

        return [
            $parts[0] ?? 'Officer',
            $parts[1] ?? ($parts[0] ?? 'Sample'),
            count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : null,
        ];
    }

    protected function guessSex(string $name): string
    {
        $femaleMarkers = ['Amina', 'Hauwa', 'Zainab', 'Maryam', 'Fatima', 'Grace', 'Rukayya'];

        foreach ($femaleMarkers as $marker) {
            if (str_contains($name, $marker)) {
                return 'female';
            }
        }

        return 'male';
    }
}
