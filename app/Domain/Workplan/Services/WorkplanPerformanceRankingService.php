<?php

namespace App\Domain\Workplan\Services;

use App\Enums\WorkplanTargetPeriod;

/**
 * Phase 4D policy layer. It consumes State observations and never rebuilds
 * achievement, completeness, evidence, expenditure, or revision resolution.
 */
class WorkplanPerformanceRankingService
{
    private float $threshold;

    public function __construct(private StateWorkplanPerformanceService $state)
    {
        $this->threshold = (float) config('workplan_performance.ranking_completeness_threshold', 0.80);
    }

    public function rankings(int $year, string|WorkplanTargetPeriod $period): array
    {
        $period = $period instanceof WorkplanTargetPeriod ? $period->value : WorkplanTargetPeriod::from($period)->value;
        $state = $this->state->calculate($year, $period);
        $rows = array_map(fn (array $mda) => $this->rankRow($mda), $state['mdas']);

        $eligible = array_values(array_filter($rows, fn ($row) => $row['rank_eligible']));
        usort($eligible, function ($left, $right): int {
            $scoreOrder = $right['ranking_score'] <=> $left['ranking_score'];
            return $scoreOrder !== 0 ? $scoreOrder : strcasecmp($left['name'], $right['name']);
        });
        $rank = 0; $position = 0; $previousScore = null;
        foreach ($eligible as &$row) {
            $position++;
            if ($previousScore === null || $row['ranking_score'] !== $previousScore) {
                $rank = $position;
                $previousScore = $row['ranking_score'];
            }
            $row['rank'] = $rank;
        }
        unset($row);

        $ineligible = array_values(array_filter($rows, fn ($row) => ! $row['rank_eligible']));
        usort($ineligible, fn ($left, $right) => strcasecmp($left['name'], $right['name']));

        return [
            'year' => $year,
            'period' => $period,
            'minimum_reporting_completeness' => $this->threshold,
            'state' => $state['state'],
            'mdas' => [...$eligible, ...$ineligible],
        ];
    }

    public function trends(int $year, int $mdaId): array
    {
        $observations = [];
        foreach ([WorkplanTargetPeriod::Q1, WorkplanTargetPeriod::Q2, WorkplanTargetPeriod::Q3, WorkplanTargetPeriod::Q4, WorkplanTargetPeriod::ANNUAL] as $period) {
            $state = $this->state->calculate($year, $period, $mdaId);
            $mda = $state['mdas'][0] ?? null;
            $observations[] = $mda ? [
                'period' => $period->value,
                'workplan_id' => $mda['workplan_id'],
                'revision_no' => $mda['revision_no'],
                'official_score' => $mda['official_score'],
                'raw_achievement_ratio' => $mda['raw_achievement_ratio'],
                'reporting_completeness' => $mda['reporting_completeness'],
                'evidence_coverage' => $mda['evidence_coverage'],
                'financial_execution' => $mda['financial_execution'],
                ...$this->rankRow($mda),
            ] : ['period'=>$period->value, 'workplan_id'=>null, 'revision_no'=>null, 'official_score'=>null, 'raw_achievement_ratio'=>null, 'reporting_completeness'=>['expected_reports'=>0, 'verified_reports'=>0, 'missing_or_unverified_reports'=>0, 'ratio'=>null], 'evidence_coverage'=>['verified_reports'=>0, 'verified_reports_with_evidence'=>0, 'ratio'=>null], 'financial_execution'=>['planned_cost'=>0.0, 'reported_expenditure'=>0.0, 'ratio'=>null], 'rank'=>null, 'rank_eligible'=>false, 'rank_status'=>'no_eligible_plan_data', 'ranking_score'=>null];
        }

        return ['year'=>$year, 'mda_id'=>$mdaId, 'minimum_reporting_completeness'=>$this->threshold, 'observations'=>$observations];
    }

    public function stateTrends(int $year): array
    {
        $series = [];
        foreach ([WorkplanTargetPeriod::Q1, WorkplanTargetPeriod::Q2, WorkplanTargetPeriod::Q3, WorkplanTargetPeriod::Q4, WorkplanTargetPeriod::ANNUAL] as $period) {
            foreach ($this->state->calculate($year, $period)['mdas'] as $mda) {
                $series[$mda['id']] ??= ['id'=>$mda['id'], 'name'=>$mda['name'], 'code'=>$mda['code'], 'observations'=>[]];
                $series[$mda['id']]['observations'][$period->value] = [
                    'workplan_id'=>$mda['workplan_id'], 'revision_no'=>$mda['revision_no'], 'official_score'=>$mda['official_score'], 'raw_achievement_ratio'=>$mda['raw_achievement_ratio'], 'reporting_completeness'=>$mda['reporting_completeness'], 'evidence_coverage'=>$mda['evidence_coverage'], 'financial_execution'=>$mda['financial_execution'], ...$this->rankRow($mda),
                ];
            }
        }
        $rows = array_values($series); usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return ['year'=>$year, 'minimum_reporting_completeness'=>$this->threshold, 'periods'=>['q1','q2','q3','q4','annual'], 'mdas'=>$rows];
    }

    private function rankRow(array $mda): array
    {
        $hasEligiblePlan = (float) ($mda['eligible_weight'] ?? 0) > 0;
        $completeness = $mda['reporting_completeness']['ratio'] ?? null;
        $score = $mda['official_score'] === null ? null : min((float) $mda['official_score'], 1.0);
        $status = ! $hasEligiblePlan || $completeness === null ? 'no_eligible_plan_data' : ($completeness >= $this->threshold ? 'eligible' : 'insufficient_reporting');

        return [...$mda, 'rank'=>null, 'ranking_score'=>$score, 'rank_eligible'=>$status === 'eligible', 'rank_status'=>$status];
    }
}
