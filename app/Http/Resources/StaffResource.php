<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_number' => $this->staff_number,
            'full_name' => $this->full_name,
            'surname' => $this->surname,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'sex' => $this->sex,
            'mda' => $this->mda?->only(['id', 'code', 'name']),
            'legacy_cno' => $this->legacy_cno,
            'legacy_psn' => $this->legacy_psn,
            'status' => $this->status,
            'department' => $this->currentEmployment?->department?->name,
            'station' => $this->currentEmployment?->station?->name,
            'cadre' => $this->currentEmployment?->cadre?->name,
            'rank' => $this->currentEmployment?->rank?->name,
            'salary_scale' => $this->currentSalaryPlacement?->salaryScale?->code,
            'level' => $this->currentSalaryPlacement?->level,
            'step' => $this->currentSalaryPlacement?->step,
            'salary_display' => $this->currentSalaryPlacement?->salaryScale?->code
                ? sprintf('%s %s/%s', $this->currentSalaryPlacement->salaryScale->code, $this->currentSalaryPlacement->level ?? '-', $this->currentSalaryPlacement->step ?? '-')
                : null,
        ];
    }
}
