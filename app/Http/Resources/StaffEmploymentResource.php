<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffEmploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mda_id' => $this->mda_id,
            'mda_name' => $this->mda?->name,
            'department_id' => $this->department_id,
            'department_name' => $this->department?->name,
            'station_id' => $this->station_id,
            'station_name' => $this->station?->name,
            'location_name' => $this->location_name,
            'cadre_id' => $this->cadre_id,
            'cadre_name' => $this->cadre?->name,
            'rank_id' => $this->rank_id,
            'rank_name' => $this->rank?->name,
            'staff_category' => $this->staff_category,
            'initial_rank' => $this->initial_rank,
            'date_first_appointment' => optional($this->date_first_appointment)?->toDateString(),
            'date_last_promotion' => optional($this->date_last_promotion)?->toDateString(),
            'expected_retirement_date' => optional($this->expected_retirement_date)?->toDateString(),
            'next_promotion_date' => optional($this->next_promotion_date)?->toDateString(),
            'employment_status' => $this->employment_status,
            'is_current' => (bool) $this->is_current,
            'effective_from' => optional($this->effective_from)?->toDateString(),
            'effective_to' => optional($this->effective_to)?->toDateString(),
        ];
    }
}
