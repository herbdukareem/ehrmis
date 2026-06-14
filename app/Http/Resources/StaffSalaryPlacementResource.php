<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffSalaryPlacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salary_scale_id' => $this->salary_scale_id,
            'salary_scale_code' => $this->salaryScale?->code,
            'salary_scale_name' => $this->salaryScale?->name,
            'level' => $this->level,
            'step' => $this->step,
            'basic_salary' => $this->basic_salary !== null ? (float) $this->basic_salary : null,
            'gross_salary' => $this->gross_salary !== null ? (float) $this->gross_salary : null,
            'basic_salary_snapshot' => $this->basic_salary_snapshot !== null ? (float) $this->basic_salary_snapshot : null,
            'legacy_gross_salary_snapshot' => $this->legacy_gross_salary_snapshot !== null ? (float) $this->legacy_gross_salary_snapshot : null,
            'calculated_gross_salary_snapshot' => $this->calculated_gross_salary_snapshot !== null ? (float) $this->calculated_gross_salary_snapshot : null,
            'gross_difference_snapshot' => $this->gross_difference_snapshot !== null ? (float) $this->gross_difference_snapshot : null,
            'source' => $this->source,
            'is_current' => (bool) $this->is_current,
            'effective_from' => optional($this->effective_from)?->toDateString(),
            'effective_to' => optional($this->effective_to)?->toDateString(),
        ];
    }
}
