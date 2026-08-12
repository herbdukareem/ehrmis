<?php

namespace App\Domain\Workplan\Services;

use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use App\Enums\WorkplanProgressStatus;
use App\Enums\WorkplanTargetPeriod;

/**
 * Phase 4A official-performance calculation contract.
 *
 * This service is deliberately read-only. It uses only verified reports from
 * the supplied Workplan revision; draft, submitted and returned reports are
 * represented as missing (zero official contribution), never as provisional
 * official data.
 */
class WorkplanPerformanceCalculationService
{
    public function __construct(private WorkplanIndicatorAchievementService $achievement) {}

    public function calculate(Workplan $workplan, string|WorkplanTargetPeriod $period): array
    {
        $period = $period instanceof WorkplanTargetPeriod ? $period->value : WorkplanTargetPeriod::from($period)->value;
        $workplan->load('objectives.department', 'objectives.activities.indicators.targets');

        $reports = WorkplanProgressReport::withoutGlobalScopes()
            ->where('mda_id', $workplan->mda_id)
            ->where('workplan_id', $workplan->id)
            ->where('period', $period)
            ->with(['indicators', 'evidence'])
            ->get()
            ->keyBy('workplan_activity_id');

        $objectives = [];
        $allActivities = [];
        $expectedReports = 0;
        $verifiedReports = 0;
        $verifiedWithEvidence = 0;
        $reportedExpenditure = 0.0;
        $plannedCost = 0.0;

        foreach ($workplan->objectives as $objective) {
            $activities = [];

            foreach ($objective->activities as $activity) {
                $targets = $activity->indicators->mapWithKeys(fn ($indicator) => [$indicator->id => $indicator->targets->first(fn ($target) => $target->period?->value === $period)]);
                $eligibleIndicators = $targets->filter();
                if ($eligibleIndicators->isEmpty()) {
                    continue;
                }

                $expectedReports++;
                $plannedCost += (float) ($activity->planned_cost ?? 0);
                $report = $reports->get($activity->id);
                $verified = $report?->status === WorkplanProgressStatus::VERIFIED;
                if ($verified) {
                    $verifiedReports++;
                    $reportedExpenditure += (float) ($report->reported_expenditure ?? 0);
                    if ($report->evidence->isNotEmpty()) {
                        $verifiedWithEvidence++;
                    }
                }

                $progresses = $verified ? $report->indicators->keyBy('workplan_indicator_id') : collect();
                $indicators = [];
                foreach ($eligibleIndicators as $indicatorId => $target) {
                    $indicator = $activity->indicators->firstWhere('id', $indicatorId);
                    $progress = $progresses->get($indicatorId);
                    $targetSnapshot = $progress?->target_value_snapshot ?? $target->target_value;
                    $rawRatio = $verified ? $this->achievement->ratio($indicator, $targetSnapshot, $progress?->actual_value) : null;
                    $officialScore = $rawRatio === null ? 0.0 : min($rawRatio, 1.0);

                    $indicators[] = [
                        'id' => $indicator->id,
                        'code' => $indicator->code,
                        'weight' => (float) $indicator->weight,
                        'eligible_planning_weight' => max(0.0, (float) $objective->performance_weight) * max(0.0, (float) $activity->performance_weight) * max(0.0, (float) $indicator->weight),
                        'target_value' => $targetSnapshot === null ? null : (float) $targetSnapshot,
                        'actual_value' => $progress?->actual_value === null ? null : (float) $progress->actual_value,
                        'raw_achievement_ratio' => $rawRatio,
                        'official_score' => $officialScore,
                        'verified' => $verified,
                    ];
                }

                $score = $this->aggregate($indicators);
                $planningWeight = array_sum(array_column($indicators, 'eligible_planning_weight'));
                $activityResult = [
                    'id' => $activity->id,
                    'code' => $activity->activity_code,
                    'title' => $activity->title,
                    'weight' => (float) $activity->performance_weight,
                    'eligible' => $planningWeight > 0,
                    'report_status' => $report?->status?->value,
                    'verified' => $verified,
                    'has_evidence' => $verified && $report->evidence->isNotEmpty(),
                    'planned_cost' => (float) ($activity->planned_cost ?? 0),
                    'verified_reported_expenditure' => $verified ? (float) ($report->reported_expenditure ?? 0) : 0.0,
                    'raw_achievement_ratio' => $score['raw_achievement_ratio'],
                    'official_score' => $score['official_score'],
                    'eligible_weight' => $score['eligible_weight'],
                    'eligible_planning_weight' => $planningWeight,
                    'indicators' => $indicators,
                ];
                if ($planningWeight > 0) {
                    $activities[] = $activityResult;
                    $allActivities[] = $activityResult;
                }
            }

            $score = $this->aggregate($activities);
            $metrics = $this->operationalMetrics($activities);
            if ($activities !== []) {
                $objectives[] = [
                    'id' => $objective->id,
                    'code' => $objective->code,
                    'title' => $objective->title,
                    'department_id' => $objective->department_id,
                    'department_name' => $objective->department?->name,
                    'weight' => (float) $objective->performance_weight,
                    'eligible' => $score['eligible_weight'] > 0,
                    'raw_achievement_ratio' => $score['raw_achievement_ratio'],
                    'official_score' => $score['official_score'],
                    'eligible_weight' => $score['eligible_weight'],
                    'eligible_planning_weight' => array_sum(array_column($activities, 'eligible_planning_weight')),
                    ...$metrics,
                    'activities' => $activities,
                ];
            }
        }

        $departments = collect($objectives)->groupBy(fn ($objective) => $objective['department_id'] ?? 'unassigned')->map(function ($departmentObjectives, $departmentId) {
            $score = $this->aggregate($departmentObjectives->all());
            $metrics = $this->operationalMetrics($departmentObjectives->flatMap(fn ($objective) => $objective['activities'])->all());
            return [
                'id' => $departmentId === 'unassigned' ? null : (int) $departmentId,
                'name' => $departmentObjectives->first()['department_name'] ?? 'Unassigned',
                // Departments have no independent planning weight. Their eligible
                // objective-weight total carries forward into the MDA roll-up.
                'weight' => $score['eligible_weight'],
                'eligible' => $score['eligible_weight'] > 0,
                'raw_achievement_ratio' => $score['raw_achievement_ratio'],
                'official_score' => $score['official_score'],
                'eligible_weight' => $score['eligible_weight'],
                'eligible_planning_weight' => array_sum(array_column($departmentObjectives->all(), 'eligible_planning_weight')),
                ...$metrics,
                'objectives' => $departmentObjectives->values()->all(),
            ];
        })->values()->all();

        $mdaScore = $this->aggregate($departments);
        $mdaMetrics = $this->operationalMetrics($allActivities);
        $mdaPlanningWeight = array_sum(array_column($departments, 'eligible_planning_weight'));

        return [
            'workplan_id' => $workplan->id,
            'mda_id' => $workplan->mda_id,
            'year' => $workplan->year,
            'revision_no' => $workplan->revision_no,
            'period' => $period,
            ...$mdaMetrics,
            'objectives' => $objectives,
            'departments' => $departments,
            'mda' => [
                'official_score' => $mdaScore['official_score'],
                'raw_achievement_ratio' => $mdaScore['raw_achievement_ratio'],
                'eligible_weight' => $mdaScore['eligible_weight'],
                'eligible_planning_weight' => $mdaPlanningWeight,
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function aggregate(array $items): array
    {
        $items = array_values(array_filter($items, fn ($item) => ($item['eligible'] ?? true) === true));
        $weight = array_sum(array_map(fn ($item) => max(0.0, (float) ($item['weight'] ?? 0)), $items));
        if ($weight <= 0) {
            return ['official_score' => null, 'raw_achievement_ratio' => null, 'eligible_weight' => 0.0];
        }

        $official = array_sum(array_map(fn ($item) => max(0.0, (float) $item['weight']) * (float) ($item['official_score'] ?? 0), $items)) / $weight;
        $raw = array_sum(array_map(fn ($item) => max(0.0, (float) $item['weight']) * (float) ($item['raw_achievement_ratio'] ?? 0), $items)) / $weight;

        return ['official_score' => $official, 'raw_achievement_ratio' => $raw, 'eligible_weight' => $weight];
    }

    /** @param array<int, array<string, mixed>> $activities */
    private function operationalMetrics(array $activities): array
    {
        $expected = count($activities);
        $verified = count(array_filter($activities, fn ($activity) => $activity['verified']));
        $withEvidence = count(array_filter($activities, fn ($activity) => $activity['verified'] && $activity['has_evidence']));
        $plannedCost = array_sum(array_map(fn ($activity) => (float) $activity['planned_cost'], $activities));
        $reportedExpenditure = array_sum(array_map(fn ($activity) => (float) $activity['verified_reported_expenditure'], $activities));

        return [
            'reporting_completeness' => ['expected_reports'=>$expected, 'verified_reports'=>$verified, 'missing_or_unverified_reports'=>$expected - $verified, 'ratio'=>$expected === 0 ? null : (float) $verified / $expected],
            'evidence_coverage' => ['verified_reports'=>$verified, 'verified_reports_with_evidence'=>$withEvidence, 'ratio'=>$verified === 0 ? null : (float) $withEvidence / $verified],
            'financial_execution' => ['planned_cost'=>$plannedCost, 'reported_expenditure'=>$reportedExpenditure, 'ratio'=>$plannedCost <= 0 ? null : $reportedExpenditure / $plannedCost],
        ];
    }
}
