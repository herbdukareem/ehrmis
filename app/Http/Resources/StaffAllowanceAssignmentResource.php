<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffAllowanceAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'allowance_type_id' => $this->allowance_type_id,
            'allowance_code' => $this->allowanceType?->code,
            'allowance_name' => $this->allowanceType?->name,
            'is_eligible' => (bool) $this->is_eligible,
            'source' => $this->source,
            'effective_from' => optional($this->effective_from)?->toDateString(),
            'effective_to' => optional($this->effective_to)?->toDateString(),
        ];
    }
}
