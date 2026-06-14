<?php

namespace App\Domain\Legacy\Services;

class LegacyStaffRowValidator
{
    /**
     * @param  array<string, mixed>  $normalizedRow
     * @return array<int, array{field: ?string, error_code: string, message: string, severity: string}>
     */
    public function validate(array $normalizedRow): array
    {
        $issues = $normalizedRow['issues'] ?? [];

        if (empty($normalizedRow['staff_number']) && empty($normalizedRow['legacy_cno']) && empty($normalizedRow['legacy_psn'])) {
            $issues[] = $this->error('staff_number', 'missing_identifier', 'Staff row has no usable identifier.');
        }

        if (empty($normalizedRow['mda_id'])) {
            $issues[] = $this->error('mda', 'missing_mda', 'MDA could not be resolved.');
        }

        if (empty($normalizedRow['full_name'])) {
            $issues[] = $this->error('full_name', 'missing_name', 'Full name could not be resolved.');
        }

        if (! empty($normalizedRow['sex']) && ! in_array($normalizedRow['sex'], ['male', 'female'], true)) {
            $issues[] = $this->warning('sex', 'invalid_sex', 'Sex value could not be normalized.');
        }

        if (($normalizedRow['salary_scale_code'] ?? null) && empty($normalizedRow['salary_scale_id'])) {
            $issues[] = $this->warning('salary_scale', 'missing_salary_scale', 'Salary scale `'.$normalizedRow['salary_scale_code'].'` could not be resolved.');
        }

        if (($normalizedRow['department_name'] ?? null) && empty($normalizedRow['department_id'])) {
            $issues[] = $this->warning('department', 'missing_department', 'Department `'.$normalizedRow['department_name'].'` could not be resolved.');
        }

        if (($normalizedRow['station_name'] ?? null) && empty($normalizedRow['station_id'])) {
            $issues[] = $this->warning('station', 'missing_station', 'Station `'.$normalizedRow['station_name'].'` could not be resolved.');
        }

        if (($normalizedRow['cadre_name'] ?? null) && empty($normalizedRow['cadre_id'])) {
            $issues[] = $this->warning('cadre', 'missing_cadre', 'Cadre `'.$normalizedRow['cadre_name'].'` could not be resolved.');
        }

        if (($normalizedRow['rank_name'] ?? null) && empty($normalizedRow['rank_id'])) {
            $issues[] = $this->warning('rank', 'missing_rank', 'Rank `'.$normalizedRow['rank_name'].'` could not be resolved.');
        }

        if (($normalizedRow['highest_qualification_name'] ?? $normalizedRow['qualification_name'] ?? null) && empty($normalizedRow['qualification_type_id'])) {
            $issues[] = $this->warning('qualification', 'missing_qualification', 'Qualification could not be resolved to a canonical qualification type.');
        }

        if (($normalizedRow['level'] ?? null) === null && ($normalizedRow['salary_scale_code'] ?? null)) {
            $issues[] = $this->warning('level', 'missing_level', 'Level is missing for a row with salary scale information.');
        }

        if (($normalizedRow['step'] ?? null) === null && ($normalizedRow['salary_scale_code'] ?? null)) {
            $issues[] = $this->warning('step', 'missing_step', 'Step is missing for a row with salary scale information.');
        }

        if (! empty($normalizedRow['is_duplicate'])) {
            $issues[] = $this->warning('duplicate', 'duplicate_risk', 'Legacy row is already marked as a duplicate risk.');
        }

        return $issues;
    }

    /**
     * @param  array<int, array{severity: string}>  $issues
     */
    public function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? 'warning') === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{field: string, error_code: string, message: string, severity: string}
     */
    protected function error(string $field, string $errorCode, string $message): array
    {
        return [
            'field' => $field,
            'error_code' => $errorCode,
            'message' => $message,
            'severity' => 'error',
        ];
    }

    /**
     * @return array{field: string, error_code: string, message: string, severity: string}
     */
    protected function warning(string $field, string $errorCode, string $message): array
    {
        return [
            'field' => $field,
            'error_code' => $errorCode,
            'message' => $message,
            'severity' => 'warning',
        ];
    }
}
