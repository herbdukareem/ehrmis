<?php

namespace App\Domain\Movement\Services;

use App\Domain\Movement\Models\MovementWorkbook;
use Illuminate\Support\Collection;

class MovementDepartmentSummaryService
{
    /**
     * @return Collection<int, array{department_id: int|null, department: string, rows: array<int, array<string, int|string|null>>}>
     */
    public function summarize(MovementWorkbook $workbook): Collection
    {
        $lines = $workbook->lines()
            ->with(['currentEmployment.department', 'currentSalaryScale', 'proposedSalaryScale'])
            ->get();
        $rows = [];
        $scaleRanges = [];

        foreach ($lines as $line) {
            $departmentId = $line->currentEmployment?->department_id;
            $department = $line->currentEmployment?->department?->name ?? 'Unassigned';
            $currentScaleId = $line->current_salary_scale_id;
            $currentScale = $line->currentSalaryScale?->code ?? 'Unassigned';
            $currentLevel = $line->current_level;
            $proposedScaleId = $line->proposed_salary_scale_id;
            $proposedScale = $line->proposedSalaryScale?->code ?? $currentScale;
            $proposedLevel = $line->proposed_level;
            $isRetiring = in_array($line->retirement_status, ['retiring', 'retired'], true);
            $isIncluded = $line->selection_state === 'included' && ! $isRetiring;
            $isMoving = $isIncluded && ($currentScaleId !== $proposedScaleId || $currentLevel !== $proposedLevel);

            $this->registerScaleRange($scaleRanges, $departmentId, $department, $line->currentSalaryScale, $currentLevel);
            $this->registerScaleRange($scaleRanges, $departmentId, $department, $line->proposedSalaryScale, $proposedLevel);

            $currentKey = implode('|', [$departmentId ?? 0, $currentScaleId ?? 0, $currentLevel ?? 0]);
            $this->initializeRow($rows, $currentKey, $departmentId, $department, $currentScale, $currentLevel);
            $rows[$currentKey]['present_staff']++;

            if ($isMoving) {
                $rows[$currentKey]['staff_moving']++;
            }

            if ($isRetiring) {
                $rows[$currentKey]['staff_retiring']++;
            }

            if ($isIncluded) {
                $proposedKey = implode('|', [$departmentId ?? 0, $proposedScaleId ?? 0, $proposedLevel ?? 0]);
                $this->initializeRow($rows, $proposedKey, $departmentId, $department, $proposedScale, $proposedLevel);

                if ($isMoving) {
                    $rows[$proposedKey]['staff_joining']++;
                }
            }
        }

        foreach ($scaleRanges as $departmentRanges) {
            foreach ($departmentRanges as $range) {
                for ($level = $range['max_level']; $level >= $range['min_level']; $level--) {
                    $key = implode('|', [$range['department_id'] ?? 0, $range['salary_scale_id'] ?? 0, $level]);
                    $this->initializeRow($rows, $key, $range['department_id'], $range['department'], $range['scale'], $level);
                }
            }
        }

        foreach ($rows as &$row) {
            $row['expected_total'] = $row['present_staff'] - $row['staff_moving'] - $row['staff_retiring'] + $row['staff_joining'];
        }

        return collect($rows)
            ->sortBy([
                ['department', 'asc'],
                ['scale', 'asc'],
                ['level', 'desc'],
            ])
            ->groupBy(fn (array $row): string => ($row['department_id'] ?? 0).'|'.$row['department'])
            ->map(function (Collection $departmentRows, string $key): array {
                [$departmentId, $department] = explode('|', $key, 2);

                return [
                    'department_id' => (int) $departmentId ?: null,
                    'department' => $department,
                    'rows' => $departmentRows->values()->all(),
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, array<string, int|string|null>>  $rows
     */
    protected function initializeRow(array &$rows, string $key, ?int $departmentId, string $department, string $scale, ?int $level): void
    {
        $rows[$key] ??= [
            'department_id' => $departmentId,
            'department' => $department,
            'scale' => $scale,
            'level' => $level,
            'present_staff' => 0,
            'staff_moving' => 0,
            'staff_joining' => 0,
            'staff_retiring' => 0,
            'expected_total' => 0,
        ];
    }

    /**
     * @param  array<string, array<string, array<string, int|string|null>>>  $scaleRanges
     */
    protected function registerScaleRange(array &$scaleRanges, ?int $departmentId, string $department, mixed $salaryScale, ?int $level): void
    {
        if ($salaryScale === null) {
            return;
        }

        $departmentKey = (string) ($departmentId ?? 0);
        $scaleKey = (string) $salaryScale->id;
        $scaleRanges[$departmentKey][$scaleKey] = [
            'department_id' => $departmentId,
            'department' => $department,
            'salary_scale_id' => $salaryScale->id,
            'scale' => $salaryScale->code,
            'min_level' => (int) ($salaryScale->min_level ?? $level ?? 1),
            'max_level' => (int) ($salaryScale->max_level ?? $level ?? 1),
        ];
    }
}
