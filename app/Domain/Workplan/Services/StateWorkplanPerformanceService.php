<?php

namespace App\Domain\Workplan\Services;

use App\Domain\Organization\Models\Mda;
use App\Domain\Workplan\Models\Workplan;
use App\Domain\Workplan\Models\WorkplanProgressReport;
use App\Enums\WorkplanStatus;
use App\Enums\WorkplanTargetPeriod;

/**
 * Phase 4C state aggregation contract.
 *
 * The service aggregates the calculation engine's eligible planning weights
 * and raw report counts. It never averages MDA percentages. A selected period
 * resolves an MDA's governing revision from period-owned progress first; in
 * its absence, the active revision is selected so missing reports remain an
 * observable reporting gap.
 */
class StateWorkplanPerformanceService
{
    public function __construct(private WorkplanPerformanceCalculationService $performance) {}

    public function calculate(int $year, string|WorkplanTargetPeriod $period, ?int $mdaId = null): array
    {
        $period = $period instanceof WorkplanTargetPeriod ? $period->value : WorkplanTargetPeriod::from($period)->value;
        $mdas = Mda::query()->when($mdaId, fn ($query) => $query->whereKey($mdaId))->orderBy('name')->get();
        $mdasResult = [];

        foreach ($mdas as $mda) {
            $workplan = $this->governingWorkplan($mda->id, $year, $period);
            if (!$workplan) {
                continue;
            }

            $result = $this->performance->calculate($workplan, $period);
            if (($result['mda']['eligible_planning_weight'] ?? 0) <= 0) {
                $mdasResult[] = [
                    'id'=>$mda->id, 'code'=>$mda->code, 'name'=>$mda->name, 'workplan_id'=>$workplan->id, 'revision_no'=>$workplan->revision_no,
                    'official_score'=>null, 'raw_achievement_ratio'=>null, 'eligible_weight'=>0.0,
                    'reporting_completeness'=>$result['reporting_completeness'], 'evidence_coverage'=>$result['evidence_coverage'], 'financial_execution'=>$result['financial_execution'],
                ];
                continue;
            }

            $mdasResult[] = [
                'id' => $mda->id,
                'code' => $mda->code,
                'name' => $mda->name,
                'workplan_id' => $workplan->id,
                'revision_no' => $workplan->revision_no,
                'official_score' => $result['mda']['official_score'],
                'raw_achievement_ratio' => $result['mda']['raw_achievement_ratio'],
                'eligible_weight' => $result['mda']['eligible_planning_weight'],
                'reporting_completeness' => $result['reporting_completeness'],
                'evidence_coverage' => $result['evidence_coverage'],
                'financial_execution' => $result['financial_execution'],
            ];
        }

        $eligibleMdas = array_values(array_filter($mdasResult, fn ($mda) => (float) $mda['eligible_weight'] > 0));
        $totalWeight = array_sum(array_map(fn ($mda) => (float) $mda['eligible_weight'], $eligibleMdas));
        $weightedOfficial = array_sum(array_map(fn ($mda) => (float) $mda['eligible_weight'] * (float) ($mda['official_score'] ?? 0), $eligibleMdas));
        $weightedRaw = array_sum(array_map(fn ($mda) => (float) $mda['eligible_weight'] * (float) ($mda['raw_achievement_ratio'] ?? 0), $eligibleMdas));
        $expected = array_sum(array_map(fn ($mda) => $mda['reporting_completeness']['expected_reports'], $eligibleMdas));
        $verified = array_sum(array_map(fn ($mda) => $mda['reporting_completeness']['verified_reports'], $eligibleMdas));
        $evidence = array_sum(array_map(fn ($mda) => $mda['evidence_coverage']['verified_reports_with_evidence'], $eligibleMdas));
        $planned = array_sum(array_map(fn ($mda) => $mda['financial_execution']['planned_cost'], $eligibleMdas));
        $reported = array_sum(array_map(fn ($mda) => $mda['financial_execution']['reported_expenditure'], $eligibleMdas));

        return [
            'year' => $year,
            'period' => $period,
            'mda_count_expected_to_report' => count($eligibleMdas),
            'mda_count_with_verified_reporting' => count(array_filter($eligibleMdas, fn ($mda) => $mda['reporting_completeness']['verified_reports'] > 0)),
            'state' => [
                'official_score' => $totalWeight <= 0 ? null : $weightedOfficial / $totalWeight,
                'raw_achievement_ratio' => $totalWeight <= 0 ? null : $weightedRaw / $totalWeight,
                'eligible_weight' => $totalWeight,
                'reporting_completeness' => ['expected_reports'=>$expected, 'verified_reports'=>$verified, 'missing_or_unverified_reports'=>$expected - $verified, 'ratio'=>$expected === 0 ? null : (float) $verified / $expected],
                'evidence_coverage' => ['verified_reports'=>$verified, 'verified_reports_with_evidence'=>$evidence, 'ratio'=>$verified === 0 ? null : (float) $evidence / $verified],
                'financial_execution' => ['planned_cost'=>$planned, 'reported_expenditure'=>$reported, 'ratio'=>$planned <= 0 ? null : $reported / $planned],
            ],
            // Alphabetical order is intentional: this is not a ranking.
            'mdas' => $mdasResult,
        ];
    }

    private function governingWorkplan(int $mdaId, int $year, string $period): ?Workplan
    {
        $workplans = Workplan::withoutGlobalScopes()->where('mda_id', $mdaId)->where('year', $year)->get();
        if ($workplans->isEmpty()) {
            return null;
        }

        $reportCounts = WorkplanProgressReport::withoutGlobalScopes()->where('mda_id', $mdaId)->where('period', $period)->whereIn('workplan_id', $workplans->pluck('id'))->selectRaw('workplan_id, count(*) as aggregate')->groupBy('workplan_id')->pluck('aggregate', 'workplan_id');
        $reported = $workplans->filter(fn ($workplan) => $reportCounts->has($workplan->id));
        if ($reported->isNotEmpty()) {
            return $reported->sortByDesc(fn ($workplan) => [(int) $reportCounts[$workplan->id], $workplan->revision_no, $workplan->id])->first();
        }

        return $workplans->where('status', WorkplanStatus::ACTIVE)->sortByDesc(fn ($workplan) => [$workplan->revision_no, $workplan->id])->first()
            ?? $workplans->whereNotIn('status', [WorkplanStatus::DRAFT, WorkplanStatus::REJECTED])->sortByDesc(fn ($workplan) => [$workplan->revision_no, $workplan->id])->first();
    }
}
