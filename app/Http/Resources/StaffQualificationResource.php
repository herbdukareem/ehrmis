<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffQualificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'qualification_type_id' => $this->qualification_type_id,
            'qualification_type_code' => $this->qualificationType?->code,
            'qualification_type_name' => $this->qualificationType?->name,
            'qualification_name' => $this->qualification_name,
            'highest_qualification_name' => $this->highest_qualification_name,
            'specialization' => $this->specialization,
            'is_highest' => (bool) $this->is_highest,
            'source' => $this->source,
        ];
    }
}
